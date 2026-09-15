<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Entity\PrintJob;
use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Hook\CronHooks;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests CronHooks::cron()'s print job retention cleanup.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregCronKernelTest extends KernelTestBase {

  protected const CURRENT_TIME = 1700000000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'options',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('conreg_printer');
    $this->installEntitySchema('conreg_print_job');

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::CURRENT_TIME);
    $this->container->set('datetime.time', $time);

    $this->container->get('config.factory')
      ->getEditable('conreg.label_printing.settings')
      ->set('retention_days', 30)
      ->save();
  }

  /**
   * Test that jobs past retention are deleted and recent ones are kept.
   *
   * Deletion is based on `changed` regardless of status - a `done` job
   * and a still-`pending` one are treated the same once both are past
   * the retention window.
   */
  public function testCronDeletesOnlyJobsPastRetention(): void {
    $printer = Printer::create(['eid' => 1, 'name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins']);
    $printer->save();

    $old = PrintJob::create([
      'eid' => 1,
      'mid' => 1,
      'member_name' => 'Old Job',
      'printer' => $printer->id(),
      'status' => 'done',
      'changed' => self::CURRENT_TIME - (31 * 86400),
    ]);
    $old->save();

    $recent = PrintJob::create([
      'eid' => 1,
      'mid' => 2,
      'member_name' => 'Recent Job',
      'printer' => $printer->id(),
      'status' => 'pending',
    ]);
    $recent->save();

    \Drupal::service(CronHooks::class)->cron();

    $jobStorage = $this->container->get('entity_type.manager')->getStorage('conreg_print_job');
    $jobStorage->resetCache();
    $this->assertNull(PrintJob::load($old->id()));
    $this->assertNotNull(PrintJob::load($recent->id()));
  }

}
