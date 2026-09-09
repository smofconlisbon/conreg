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
 * Tests the Event Configuration form's "Member listing page" section.
 *
 * Covers the "Default sort order" select and "Deduplicate member list"
 * checkbox added for issue #3596644 - all of it specific to how those two
 * settings are built and persisted, rather than the general "does this
 * form build" coverage in FormBuildTest.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class EventConfigMemberListingTest extends KernelTestBase {

  /**
   * A handful of legacy conreg.settings keys predate a strict schema.
   *
   * Submitting the full Event Configuration form (as the two
   * testSubmitPersists*() tests do) writes every key on the form, including
   * several - e.g. closed_message_text, member_portal.add_role - that have
   * no schema definition yet. Filling in that pre-existing gap is unrelated
   * to what this test file covers, so strict schema checking is disabled
   * here rather than left to mask an unrelated fatal error (see
   * FormBuildTest, which has the same disabling for the same reason).
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
   * With no config saved, "Default sort order" defaults to Member No.
   */
  public function testDefaultSortDefaultsToMemberNo(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertArrayHasKey('default_sort', $form['conreg_member_listing']);
    $this->assertSame('member_no', $form['conreg_member_listing']['default_sort']['#default_value']);
  }

  /**
   * The "Default sort order" select reflects a saved config value.
   */
  public function testDefaultSortReflectsConfig(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member_listing_page.default_sort', 'name')
      ->save();

    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertSame('name', $form['conreg_member_listing']['default_sort']['#default_value']);
  }

  /**
   * Changing "Default sort order" and submitting persists it to config.
   */
  public function testSubmitPersistsDefaultSort(): void {
    $formObject = EventConfig::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);

    $values = $this->extractFormValues($form);
    $values['conreg_member_listing']['default_sort'] = 'country';
    $formState->setValues($values);

    $formObject->submitForm($form, $formState);

    $this->assertSame(
      'country',
      $this->config('conreg.settings.1')->get('member_listing_page.default_sort')
    );
  }

  /**
   * With no config saved, "Deduplicate member list" defaults to unchecked.
   */
  public function testDeduplicateDefaultsToUnchecked(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertArrayHasKey('deduplicate', $form['conreg_member_listing']);
    $this->assertFalse((bool) $form['conreg_member_listing']['deduplicate']['#default_value']);
  }

  /**
   * The "Deduplicate member list" checkbox reflects a saved config value.
   */
  public function testDeduplicateReflectsConfig(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member_listing_page.deduplicate', TRUE)
      ->save();

    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertTrue((bool) $form['conreg_member_listing']['deduplicate']['#default_value']);
  }

  /**
   * Checking "Deduplicate member list" and submitting persists it to config.
   */
  public function testSubmitPersistsDeduplicate(): void {
    $formObject = EventConfig::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);

    $values = $this->extractFormValues($form);
    $values['conreg_member_listing']['deduplicate'] = 1;
    $formState->setValues($values);

    $formObject->submitForm($form, $formState);

    $this->assertTrue(
      $this->config('conreg.settings.1')->get('member_listing_page.deduplicate')
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
