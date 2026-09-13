<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Admin\EventAddOns;
use Drupal\conreg\Form\Admin\EventConfig;
use Drupal\conreg\Form\Admin\MemberClasses;
use Drupal\conreg\Form\Admin\MemberTypes;
use Drupal\conreg\Form\CheckMember;
use Drupal\conreg_airtable\ConfigAirTableForm;
use Drupal\conreg_clickup\ConregConfigClickUpOptionsForm;
use Drupal\conreg_discord\Form\ConfigDiscordForm;
use Drupal\conreg_planz\Form\ConfigPlanZForm;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Regression coverage for #3596625: count() called on a value allowing false.
 *
 * `EventStorage::load()` is declared `: array|false` and returns FALSE
 * (via `fetchAssoc()`) when the requested `eid` has no matching row. Nine
 * `buildForm()` methods across the module fetch the event with
 * `count($event = $this->eventStorage->load(['eid' => $eid])) < 3` as the
 * very first thing they do, with no check that `load()` actually returned
 * an array first. `count(FALSE)` throws
 * `TypeError: count(): Argument #1 ($value) must be of type Countable|array,
 * bool given` instead of falling through to the "Event not found" message
 * the code is clearly trying to show - exactly the same fault pattern
 * fixed for #3596624 in `Member::loadMember()`.
 *
 * `Registration::buildForm()` already has the correct guard a few lines
 * away - `if (!$event || count($event) < 3)` - the `!$event ||` short-
 * circuits before `count()` ever sees FALSE. That's the fix to replicate
 * in each of the nine classes below.
 */
#[CoversClass(CheckMember::class)]
#[CoversClass(EventConfig::class)]
#[CoversClass(EventAddOns::class)]
#[CoversClass(MemberTypes::class)]
#[CoversClass(MemberClasses::class)]
#[CoversClass(ConfigAirTableForm::class)]
#[CoversClass(ConregConfigClickUpOptionsForm::class)]
#[CoversClass(ConfigDiscordForm::class)]
#[CoversClass(ConfigPlanZForm::class)]
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class EventNotFoundBuildFormTest extends KernelTestBase {

  /**
   * Modules to enable for this test.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'conreg',
    'conreg_airtable',
    'conreg_clickup',
    'conreg_discord',
    'conreg_planz',
    'token',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * Install schema/config, but deliberately leave conreg_events empty.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'datetime']);
    $this->installSchema('conreg', ['conreg_events']);
    $this->installConfig(['conreg']);
  }

  /**
   * Form classes whose buildForm() re-derives the event from $eid first.
   *
   * @return array
   *   Test cases keyed by a readable label, each containing the form
   *   class's fully-qualified name.
   */
  public static function providerFormClasses(): array {
    return [
      'CheckMember' => [CheckMember::class],
      'EventConfig' => [EventConfig::class],
      'EventAddOns' => [EventAddOns::class],
      'MemberTypes' => [MemberTypes::class],
      'MemberClasses' => [MemberClasses::class],
      'ConfigAirTableForm' => [ConfigAirTableForm::class],
      'ConregConfigClickUpOptionsForm' => [ConregConfigClickUpOptionsForm::class],
      'ConfigDiscordForm' => [ConfigDiscordForm::class],
      'ConfigPlanZForm' => [ConfigPlanZForm::class],
    ];
  }

  /**
   * Building the form for an eid with no matching event must not error.
   *
   * Currently throws a fatal TypeError inside each of these buildForm()
   * methods, instead of returning the "Event not found" render array the
   * code already has ready to show.
   */
  #[DataProvider('providerFormClasses')]
  public function testBuildFormHandlesMissingEventGracefully(string $formClass): void {
    $form_obj = $this->container->get('class_resolver')->getInstanceFromDefinition($formClass);
    $form_state = new FormState();

    // Event ID 999999 has no matching row in conreg_events.
    $form = $form_obj->buildForm([], $form_state, 999999);

    $this->assertIsArray($form);
  }

}
