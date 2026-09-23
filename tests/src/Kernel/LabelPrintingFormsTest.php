<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Entity\LabelSize;
use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Form\Admin\PrinterForm;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
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

    // Regression test for #3596651's follow-on bug: name and machine_name
    // are required base fields, but baseFieldDefinitions() never gave
    // either a form display, so no widget for them was ever built - the
    // add form was silently missing the only two fields an admin needs
    // to fill in, and Save (see below) would persist a printer with
    // both stuck at NULL.
    $this->assertArrayHasKey('name', $form);
    $this->assertArrayHasKey('machine_name', $form);

    // Machine name is auto-suggested from Name as the admin types,
    // via core's #type => 'machine_name' element retrofitted onto the
    // field widget's own textfield.
    $machineNameField = $form['machine_name']['widget'][0]['value'];
    $this->assertSame('machine_name', $machineNameField['#type']);
    $this->assertSame(['name', 'widget', 0, 'value'], $machineNameField['#machine_name']['source']);
  }

  /**
   * The machine name uniqueness check rejects a name already in use.
   */
  public function testMachineNameExistsDetectsDuplicates(): void {
    Printer::create(['name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins', 'eid' => 1])->save();

    $formObject = PrinterForm::create($this->container);
    $formObject->setEntity(Printer::create([]));
    $formState = new FormState();

    $this->assertTrue($formObject->machineNameExists('bilbo_baggins', [], $formState));
    $this->assertFalse($formObject->machineNameExists('frodo_baggins', [], $formState));
  }

  /**
   * Editing a printer isn't flagged as a duplicate of its own machine name.
   */
  public function testMachineNameExistsIgnoresOwnEntityOnEdit(): void {
    $printer = Printer::create(['name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins', 'eid' => 1]);
    $printer->save();

    $formObject = PrinterForm::create($this->container);
    $formObject->setEntity($printer);
    $formState = new FormState();

    $this->assertFalse($formObject->machineNameExists('bilbo_baggins', [], $formState));
  }

  /**
   * Saving a printer whose label is NULL doesn't crash the status message.
   *
   * Regression test for #3596651's follow-on bug: with no widget for
   * name/machine_name (see testPrinterAddFormBuild above), a printer
   * could reach save() with a NULL label. PrinterForm::save() builds its
   * confirmation message with `'%label' => $entity->label()` unguarded,
   * so `$this->t('Created new printer %label.', ...)` carries a NULL
   * placeholder - Drupal doesn't evaluate that until the message is
   * actually rendered (cast to string), which is what this test forces,
   * the same way a real page request displaying it would.
   */
  public function testPrinterFormSaveDoesNotCrashForNamelessPrinter(): void {
    $printer = Printer::create(['eid' => 1]);

    $formObject = PrinterForm::create($this->container);
    $formObject->setEntity($printer);
    $formObject->save([], new FormState());

    $messenger = $this->container->get('messenger');
    $messages = $messenger->all();
    $this->assertNotEmpty($messages, 'Expected a status message after saving.');
    foreach ($messages as $messagesByType) {
      foreach ($messagesByType as $message) {
        // Forces evaluation of the lazy TranslatableMarkup, the same way
        // rendering the messages block on the next page would.
        (string) $message;
      }
    }
    $this->addToAssertionCount(1);
  }

  /**
   * The settings form renders when a stored printer has a NULL name.
   *
   * Regression test for #3596651's follow-on bug: LabelPrintingSettings
   * builds its "Test print" printer options with
   * `'@name' => $printer->label()` unguarded. A printer entity with a
   * NULL name/machine_name (reachable via the broken add form fixed by
   * testPrinterAddFormBuild, or from any data that predates that fix)
   * crashed the entire Settings tab with a TypeError from
   * Html::escape(), not just the printer picker.
   */
  public function testLabelPrintingSettingsFormRendersWithNamelessPrinter(): void {
    LabelSize::create([
      'id' => 'test_size',
      'label' => 'Test size',
      'width_mm' => 36,
      'height_mm' => 89,
    ])->save();

    // Bypasses the entity form entirely, the same way the broken add
    // form did in practice - the entity API itself doesn't enforce
    // "required", only forms with a widget to flag the violation on do.
    Printer::create(['eid' => 1])->save();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_label_printing_settings');
    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $this->container->get('renderer')->render($form),
    );
    $this->addToAssertionCount(1);
  }

}
