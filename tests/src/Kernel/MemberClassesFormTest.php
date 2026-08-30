<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Admin\MemberClasses;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Member Classes admin form.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MemberClassesFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * Set up database tables and config for testing the form.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['conreg']);
    $this->installSchema('conreg', ['conreg_events']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();
  }

  /**
   * Submitting Member Classes with no changes should not raise PHP warnings.
   *
   * The default installed config for the "Default" member class has no
   * "extras" key (only name/fields/mandatory/max_length), matching every
   * real site's config. MemberClasses::updateMemberClasses() unconditionally
   * loops over $class->extras, so this reproduces the reported bug: saving
   * the form with no changes triggers "Undefined property: stdClass::$extras"
   * followed by "foreach() argument must be of type array|object, null
   * given". phpunit.xml.dist sets failOnWarning="true", so those warnings
   * alone are enough to fail this test until the dead extras handling is
   * fixed.
   */
  public function testAdminMemberClassesFormSubmitDoesNotWarn(): void {
    $formObject = MemberClasses::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);

    $values = $this->extractFormValues($form);
    $formState->setValues($values);

    $formObject->submitForm($form, $formState);

    $this->assertSame(
      'Default',
      $this->config('conreg.settings.1')->get('member.classes.Default.name')
    );
  }

  /**
   * Submitting Member Classes with real changes persists them correctly.
   *
   * Covers the value transformations in updateMemberClasses(): a plain
   * label change, an emptied optional field being cleared to NULL, a
   * mandatory checkbox being unchecked, a max length being set, and the
   * age_min numeric coercion (intval()).
   */
  public function testAdminMemberClassesFormSubmitPersistsChangedValues(): void {
    $formObject = MemberClasses::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);

    $values = $this->extractFormValues($form);
    $values['Default']['class']['name'] = 'Primary Member';
    $values['Default']['labels']['first_name'] = 'Given Name Updated';
    $values['Default']['labels']['name_description'] = '';
    $values['Default']['labels']['age_min'] = '5';
    $values['Default']['mandatory']['country'] = 0;
    $values['Default']['max_length']['first_name'] = '20';
    $formState->setValues($values);

    $formObject->submitForm($form, $formState);

    $config = $this->config('conreg.settings.1');
    $this->assertSame('Primary Member', $config->get('member.classes.Default.name'));
    $this->assertSame('Given Name Updated', $config->get('member.classes.Default.fields.first_name'));
    $this->assertNull($config->get('member.classes.Default.fields.name_description'));
    $this->assertSame(5, $config->get('member.classes.Default.fields.age_min'));
    $this->assertSame(0, $config->get('member.classes.Default.mandatory.country'));
    $this->assertSame(20, $config->get('member.classes.Default.max_length.first_name'));
  }

  /**
   * Cloning a member class adds a new class with the source's field values.
   */
  public function testCloneMemberClassPersists(): void {
    $formObject = MemberClasses::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);
    $formState->setValues($this->extractFormValues($form));

    // Click "Clone Default". buildForm() was called directly rather than
    // through the form builder, so the button's #parents (normally set
    // while the form tree is processed) has to be supplied by hand.
    $formState->setTriggeringElement(['#parents' => ['Default', 'clone']]);
    $formObject->cloneSubmit($form, $formState);

    // Confirm the clone, naming the new class "VIP".
    $cloneForm = $formObject->buildForm([], $formState, 1);
    $formState->setValues(['clone_to' => 'VIP']);
    $formObject->confirmCloneSubmit($cloneForm, $formState);

    // Back on the main form, "VIP" now has its own tab with cloned values.
    $rebuiltForm = $formObject->buildForm([], $formState, 1);
    $this->assertArrayHasKey('VIP', $rebuiltForm);
    $formState->setValues($this->extractFormValues($rebuiltForm));

    // Save persists the newly cloned class alongside the original.
    $formObject->submitForm($rebuiltForm, $formState);

    $config = $this->config('conreg.settings.1');
    $this->assertSame('VIP', $config->get('member.classes.VIP.name'));
    $this->assertSame(
      $config->get('member.classes.Default.fields.first_name'),
      $config->get('member.classes.VIP.fields.first_name')
    );
    $this->assertSame('Default', $config->get('member.classes.Default.name'));
  }

  /**
   * Cloning to an existing class name shows an error and clones nothing.
   */
  public function testCloneMemberClassDuplicateNameShowsError(): void {
    $formObject = MemberClasses::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);
    $formState->setValues($this->extractFormValues($form));

    $formState->setTriggeringElement(['#parents' => ['Default', 'clone']]);
    $formObject->cloneSubmit($form, $formState);

    $cloneForm = $formObject->buildForm([], $formState, 1);
    $formState->setValues(['clone_to' => 'Default']);
    $formObject->confirmCloneSubmit($cloneForm, $formState);

    $messenger = $this->container->get('messenger');
    $errors = array_map('strval', $messenger->messagesByType(MessengerInterface::TYPE_ERROR));
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('already exists', $errors[0]);
  }

  /**
   * Deleting a member class removes it from configuration on save.
   */
  public function testDeleteMemberClassPersists(): void {
    $formObject = MemberClasses::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);
    $formState->setValues($this->extractFormValues($form));

    // Click "Delete Default". buildForm() was called directly rather than
    // through the form builder, so the button's #parents (normally set
    // while the form tree is processed) has to be supplied by hand.
    $formState->setTriggeringElement(['#parents' => ['Default', 'delete']]);
    $formObject->deleteSubmit($form, $formState);

    // Confirm the deletion.
    $deleteForm = $formObject->buildForm([], $formState, 1);
    $formObject->confirmDeleteSubmit($deleteForm, $formState);

    // Back on the main form, "Default" no longer has a tab.
    $rebuiltForm = $formObject->buildForm([], $formState, 1);
    $this->assertArrayNotHasKey('Default', $rebuiltForm);
    $formState->setValues($this->extractFormValues($rebuiltForm));

    $formObject->submitForm($rebuiltForm, $formState);

    $this->assertNull($this->config('conreg.settings.1')->get('member.classes.Default'));
  }

  /**
   * Harvest defaults from built form.
   *
   * Recursively harvests default values from a built form, keyed as
   * $form_state->getValues() would return them, so they can be resubmitted.
   */
  protected function extractFormValues(array $element): array {
    $values = [];
    foreach (Element::children($element) as $key) {
      $child = $element[$key];
      switch ($child['#type'] ?? NULL) {
        case 'vertical_tabs':
          break;

        case 'checkbox':
          $values[$key] = (int) ($child['#default_value'] ?? 0);
          break;

        case 'details':
        case 'fieldset':
          $values[$key] = $this->extractFormValues($child);
          break;

        case NULL:
          if (Element::children($child)) {
            $values[$key] = $this->extractFormValues($child);
          }
          break;

        default:
          $default = $child['#default_value'] ?? NULL;
          // A <select> with no matching default value falls back to
          // whichever option a real browser would pre-select: the first one.
          if (($default === NULL || $default === '') && !empty($child['#options'])) {
            $default = array_key_first($child['#options']);
          }
          $values[$key] = $default ?? '';
      }
    }
    return $values;
  }

}
