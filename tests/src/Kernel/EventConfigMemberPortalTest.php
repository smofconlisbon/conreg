<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Admin\EventConfig;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Event Configuration form's "Member Portal" tab.
 *
 * Covers the "Show days column" checkbox added for issue #3596647 - all of
 * it specific to how that setting is built and persisted, rather than the
 * general "does this form build" coverage in FormBuildTest.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class EventConfigMemberPortalTest extends KernelTestBase {

  /**
   * A handful of legacy conreg.settings keys predate a strict schema.
   *
   * Submitting the full Event Configuration form (as
   * testSubmitPersistsShowDaysColumn() does) writes every key on the form,
   * including several - e.g. closed_message_text - that have no schema
   * definition yet. Filling in that pre-existing gap is unrelated to what
   * this test file covers, so strict schema checking is disabled here
   * rather than left to mask an unrelated fatal error (see FormBuildTest,
   * which has the same disabling for the same reason).
   *
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

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

    // Required for #type = datetime.
    $this->installConfig(['system', 'datetime']);

    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
      'conreg_payments',
      'conreg_payment_lines',
    ]);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();
  }

  /**
   * The "Member Portal" tab sits between "Member Check" and "Self Service".
   */
  public function testTabIsPositionedBetweenMemberCheckAndMemberEdit(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $keys = array_keys($form);
    $checkPos = array_search('conreg_member_check', $keys, TRUE);
    $portalPos = array_search('conreg_member_portal', $keys, TRUE);
    $editPos = array_search('conreg_member_edit', $keys, TRUE);

    $this->assertNotFalse($checkPos);
    $this->assertNotFalse($portalPos);
    $this->assertNotFalse($editPos);
    $this->assertGreaterThan($checkPos, $portalPos);
    $this->assertLessThan($editPos, $portalPos);
  }

  /**
   * With no config saved, "Show days column" defaults to checked.
   */
  public function testShowDaysColumnDefaultsToChecked(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertArrayHasKey('show_days_column', $form['conreg_member_portal']);
    $this->assertTrue((bool) $form['conreg_member_portal']['show_days_column']['#default_value']);
  }

  /**
   * The "Show days column" checkbox reflects a saved config value.
   */
  public function testShowDaysColumnReflectsConfig(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member_portal.show_days_column', FALSE)
      ->save();

    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertFalse((bool) $form['conreg_member_portal']['show_days_column']['#default_value']);
  }

  /**
   * Unchecking "Show days column" and submitting persists it to config.
   */
  public function testSubmitPersistsShowDaysColumn(): void {
    $formObject = EventConfig::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);

    $values = $this->extractFormValues($form);
    $values['conreg_member_portal']['show_days_column'] = 0;
    $formState->setValues($values);

    $formObject->submitForm($form, $formState);

    $this->assertFalse(
      $this->config('conreg.settings.1')->get('member_portal.show_days_column')
    );
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

        case 'text_format':
          $values[$key] = [
            'value' => $child['#default_value'] ?? '',
            'format' => $child['#format'] ?? 'basic_html',
          ];
          break;

        case 'details':
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
