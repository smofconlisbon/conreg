<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Entity\PrintJob;
use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Service\PrintJobManager;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests PrintJobManager, which creates print jobs for members.
 *
 * @group conreg
 */
#[RunTestsInSeparateProcesses]
class PrintJobManagerKernelTest extends KernelTestBase {

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

    $this->installSchema('conreg', ['conreg_members', 'conreg_events']);
    $this->installConfig(['conreg']);
    $this->installEntitySchema('conreg_printer');
    $this->installEntitySchema('conreg_print_job');

    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();
  }

  /**
   * Helper: create a test member. Days use conreg.settings.1's codes
   * (Fr = Friday, Sa = Saturday, Su = Sunday - see
   * config/install/conreg.settings.1.yml).
   */
  protected function createTestMember(array $overrides = []): int {
    $defaults = [
      'mid' => 1,
      'eid' => 1,
      'language' => 'en',
      'first_name' => 'Test',
      'last_name' => 'User',
      'badge_name' => 'Jane Doe',
      'member_no' => 4021,
      'days' => 'Fr|Sa|Su',
      'email' => 'test@example.com',
    ];

    $fields = $overrides + $defaults;

    return Database::getConnection()
      ->insert('conreg_members')
      ->fields($fields)
      ->execute();
  }

  /**
   * Helper: create a printer entity.
   */
  protected function createPrinter(int $eid, string $name): Printer {
    $printer = Printer::create(['eid' => $eid, 'name' => $name]);
    $printer->save();
    return $printer;
  }

  /**
   * Test that a job snapshots the member's current badge details.
   */
  public function testCreateJobSnapshotsMemberData(): void {
    $mid = $this->createTestMember();
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);
    $job = $manager->createJob($mid, 'Bilbo Baggins');

    $this->assertSame(1, (int) $job->get('eid')->value);
    $this->assertSame($mid, (int) $job->get('mid')->value);
    $this->assertSame('Jane Doe', $job->get('member_name')->value);
    $this->assertSame('4021', $job->get('member_number')->value);
    $this->assertSame('Friday, Saturday, Sunday', $job->get('days_attending')->value);
    $this->assertSame('pending', $job->get('status')->value);
    $this->assertSame('Bilbo Baggins', $job->get('printer')->entity->get('name')->value);

    // Confirm it was actually persisted, not just held in memory.
    $this->container->get('entity_type.manager')->getStorage('conreg_print_job')->resetCache();
    $this->assertNotNull(PrintJob::load($job->id()));
  }

  /**
   * Test that a member with no days selected gets an empty days_attending.
   *
   * Drupal normalizes an empty value on a non-required string base field
   * to NULL on save, so that - not '' - is the correct "no value" here.
   */
  public function testCreateJobWithNoDaysSelected(): void {
    $mid = $this->createTestMember(['days' => '']);
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);
    $job = $manager->createJob($mid, 'Bilbo Baggins');

    $this->assertNull($job->get('days_attending')->value);
  }

  /**
   * Test that an unknown member ID is rejected.
   */
  public function testCreateJobThrowsForUnknownMember(): void {
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->createJob(999, 'Bilbo Baggins');
  }

  /**
   * Test that an unknown printer name is rejected.
   */
  public function testCreateJobThrowsForUnknownPrinter(): void {
    $mid = $this->createTestMember();

    $manager = $this->container->get(PrintJobManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->createJob($mid, 'No Such Printer');
  }

}
