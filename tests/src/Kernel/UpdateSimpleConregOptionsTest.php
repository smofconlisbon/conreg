<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Admin\EventConfig;
use Drupal\conreg\TextareaLines;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests update 9025, which restores member options lost in the rename.
 *
 * Sites upgraded from simple_conreg kept their options under the old
 * simple_conreg_options key, often with missing trailing fields that Event
 * Configuration now rejects.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class UpdateSimpleConregOptionsTest extends KernelTestBase {

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
   * Option groups as stored by simple_conreg, taken from an upgraded site.
   */
  protected const OLD_GROUPS = "1|checkboxes|access|Can we help?|0|1\r\n2|checkboxes|child_members|Responsible adult|0|1\r\n3|checkboxes|please_contact_me|Please contact me about...|0|1";

  /**
   * Options as stored by simple_conreg, with 7 and 8 fields per line.
   */
  protected const OLD_OPTIONS = "11|1|Accessibility needs|What would help you most|0|0|Default,Child\r\n21|2|Child members must be accompanied by an adult.||0|1|Child|1\r\n31|3|Programme participation||0|1|Default|";

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'datetime']);
    $this->installSchema('conreg', ['conreg_events']);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();

    $this->container->get('module_handler')->loadInclude('conreg', 'install');
  }

  /**
   * Old options are moved across, padded, and pass validation.
   */
  public function testOldOptionsAreMovedAndPadded(): void {
    $this->writeSettings([
      'conreg_options' => ['option_groups' => '', 'options' => ''],
      'simple_conreg_options' => [
        'option_groups' => self::OLD_GROUPS,
        'options' => self::OLD_OPTIONS,
      ],
    ]);

    conreg_update_9025();
    $config = $this->readSettings();

    $this->assertNull($config['simple_conreg_options'] ?? NULL);
    $this->assertSame([
      '1|checkboxes|access|Can we help?|0|1',
      '2|checkboxes|child_members|Responsible adult|0|1',
      '3|checkboxes|please_contact_me|Please contact me about...|0|1',
    ], $config['conreg_options']['option_groups']);
    $this->assertSame([
      '11|1|Accessibility needs|What would help you most|0|0|Default,Child|0|0|',
      '21|2|Child members must be accompanied by an adult.||0|1|Child|1|0|',
      // A trailing empty "must be checked" field becomes 0.
      '31|3|Programme participation||0|1|Default|0|0|',
    ], $config['conreg_options']['options']);

    $this->assertPassesValidation($config['conreg_options']);
  }

  /**
   * Short lines are padded even without the old key.
   */
  public function testShortCurrentLinesArePadded(): void {
    $this->writeSettings([
      'conreg_options' => [
        'option_groups' => ['1|checkboxes|volunteer|Volunteering|1'],
        'options' => ['5|1|Help set up||||Default'],
      ],
    ]);

    conreg_update_9025();
    $config = $this->readSettings();

    $this->assertSame(['1|checkboxes|volunteer|Volunteering|1|0'], $config['conreg_options']['option_groups']);
    $this->assertSame(['5|1|Help set up||0|0|Default|0|0|'], $config['conreg_options']['options']);
    $this->assertPassesValidation($config['conreg_options']);
  }

  /**
   * Lines with too many fields are left unchanged rather than guessed at.
   */
  public function testOverLongLinesAreLeftUnchanged(): void {
    $overLong = '5|1|Help set up||0|0|Default|0|0|a@example.com|extra';
    $this->writeSettings([
      'conreg_options' => [
        'option_groups' => ['1|checkboxes|volunteer|Volunteering|1|1'],
        'options' => [$overLong, '6|1|Help take down||0|1|Default'],
      ],
    ]);

    conreg_update_9025();
    $config = $this->readSettings();

    $this->assertSame([
      $overLong,
      '6|1|Help take down||0|1|Default|0|0|',
    ], $config['conreg_options']['options']);
  }

  /**
   * Options entered since the upgrade aren't overwritten by the old ones.
   */
  public function testCurrentOptionsAreNotOverwritten(): void {
    $current = ['1|checkboxes|volunteer|Volunteering|1|1'];
    $this->writeSettings([
      'conreg_options' => [
        'option_groups' => $current,
        'options' => [],
      ],
      'simple_conreg_options' => [
        'option_groups' => self::OLD_GROUPS,
        'options' => self::OLD_OPTIONS,
      ],
    ]);

    conreg_update_9025();
    $config = $this->readSettings();

    $this->assertSame($current, $config['conreg_options']['option_groups']);
    // The old values stay for someone to compare by hand.
    $this->assertSame(self::OLD_GROUPS, $config['simple_conreg_options']['option_groups']);
  }

  /**
   * An empty old key is simply removed.
   */
  public function testEmptyOldKeyIsRemoved(): void {
    $this->writeSettings([
      'simple_conreg_options' => ['option_groups' => '', 'options' => ''],
    ]);

    conreg_update_9025();

    $this->assertArrayNotHasKey('simple_conreg_options', $this->readSettings());
  }

  /**
   * Already valid config is left alone, and a second run changes nothing.
   */
  public function testValidConfigIsUnchangedAndUpdateIsIdempotent(): void {
    $this->writeSettings([
      'conreg_options' => ['option_groups' => '', 'options' => ''],
      'simple_conreg_options' => [
        'option_groups' => self::OLD_GROUPS,
        'options' => self::OLD_OPTIONS,
      ],
    ]);

    conreg_update_9025();
    $afterFirst = $this->readSettings();
    conreg_update_9025();

    $this->assertSame($afterFirst, $this->readSettings());
  }

  /**
   * The cached option list is cleared so the moved options take effect.
   */
  public function testFieldOptionsCacheIsCleared(): void {
    $this->writeSettings([
      'simple_conreg_options' => [
        'option_groups' => self::OLD_GROUPS,
        'options' => self::OLD_OPTIONS,
      ],
    ]);
    $cid = 'conreg:fieldOptions_1_' . $this->container->get('language_manager')->getDefaultLanguage()->getId();
    $this->container->get('cache.default')->set($cid, 'stale');

    conreg_update_9025();

    $this->assertFalse($this->container->get('cache.default')->get($cid));
  }

  /**
   * Merges values into the raw stored settings for event 1.
   *
   * Writes to config storage directly, so keys outside the schema (like
   * simple_conreg_options) are kept exactly as an upgraded site has them.
   *
   * @param array $values
   *   Top-level keys to set.
   */
  protected function writeSettings(array $values): void {
    $storage = $this->container->get('config.storage');
    $storage->write('conreg.settings.1', $values + $storage->read('conreg.settings.1'));
    $this->container->get('config.factory')->reset('conreg.settings.1');
  }

  /**
   * Reads the raw stored settings for event 1.
   *
   * @return array
   *   The stored settings.
   */
  protected function readSettings(): array {
    $this->container->get('config.factory')->reset('conreg.settings.1');
    return $this->container->get('config.storage')->read('conreg.settings.1');
  }

  /**
   * Asserts option lines pass Event Configuration's validation.
   *
   * @param array $options
   *   The conreg_options settings.
   */
  protected function assertPassesValidation(array $options): void {
    $form = $this->container->get('class_resolver')->getInstanceFromDefinition(EventConfig::class);
    $formState = new FormState();

    $groups = TextareaLines::toTextareaString($options['option_groups']);
    $lines = TextareaLines::toTextareaString($options['options']);
    $memberClassIds = ['Default' => TRUE, 'Child' => TRUE];

    // The validators are protected, so call them bound to the form.
    (function () use ($groups, $lines, $memberClassIds, $formState): void {
      $groupIds = $this->validateOptionGroups($groups, $formState);
      $this->validateOptions($lines, $groupIds, $memberClassIds, $formState);
    })->call($form);

    $this->assertSame([], array_map('strval', $formState->getErrors()));
  }

}
