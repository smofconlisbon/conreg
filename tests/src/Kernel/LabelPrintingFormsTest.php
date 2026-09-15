<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Entity\LabelSize;
use Drupal\conreg\Entity\Printer;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests the label printing settings, label size, and printer admin forms.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class LabelPrintingFormsTest extends KernelTestBase {

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

    $this->installSchema('conreg', ['conreg_events']);
    $this->installEntitySchema('conreg_printer');

    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();
  }

  /**
   * Test the global settings form builds and lists label sizes.
   */
  public function testLabelPrintingSettingsFormBuild(): void {
    LabelSize::create([
      'id' => 'test_size',
      'label' => 'Test size',
      'width_mm' => 36,
      'height_mm' => 89,
    ])->save();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_label_printing_settings');
    $this->assertInstanceOf(Route::class, $route);

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertSame('conreg_label_printing_settings', $form['#form_id']);
    $this->assertArrayHasKey('test_size', $form['label_size']['#options']);
    $this->assertArrayHasKey('badge_name', $form['field_positions']);
    $this->assertArrayHasKey('badge_type', $form['field_positions']);
  }

  /**
   * Test the label size add form builds via the entity form builder.
   */
  public function testLabelSizeAddFormBuild(): void {
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('conreg_label_size')
      ->create([]);

    $form = $this->container->get('entity.form_builder')->getForm($entity, 'add');

    $this->assertArrayHasKey('width_mm', $form);
    $this->assertArrayHasKey('height_mm', $form);
    $this->assertArrayHasKey('rotate_degrees', $form);
    $this->assertSame(90, $form['rotate_degrees']['#default_value']);
  }

  /**
   * Test the printer add form builds with an event select, not a raw eid.
   */
  public function testPrinterAddFormBuild(): void {
    $entity = Printer::create([]);

    $form = $this->container->get('entity.form_builder')->getForm($entity, 'add');

    $this->assertArrayNotHasKey('eid', $form);
    $this->assertArrayHasKey('event', $form);
    $this->assertArrayHasKey(1, $form['event']['#options']);
    $this->assertSame('Test event', $form['event']['#options'][1]);
  }

}
