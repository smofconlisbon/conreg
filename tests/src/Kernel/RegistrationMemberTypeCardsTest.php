<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\ConregOptions;
use Drupal\conreg\Form\Registration;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Registration form's member-type-cards integration.
 *
 * Covers the member_type_cards element wiring, the disabled-for-member-1
 * behavior, ConregOptions::memberTypes() label text, and the day-options
 * pricing/visibility characterization - split out of FormBuildTest.php
 * since that file is meant to stay a lightweight per-route smoke test.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class RegistrationMemberTypeCardsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'conreg',
  ];

  /**
   * Set up database tables and config for testing forms.
   */
  protected function setUp(): void {
    parent::setUp();

    // Required for #type = datetime.
    $this->installConfig(['system', 'datetime']);

    // Install the database schema for conreg.
    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
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
   * Configures three member types.
   *
   * One allowFirst, one non-allowFirst, and one with per-day pricing.
   */
  protected function createMemberTypesConfig(int $eid = 1): void {
    $config = $this->container->get('config.factory')->getEditable('conreg.settings.' . $eid);
    // Replace the default install config's member types (Adult, Low Income,
    // Child, Infant, Supporting) with a known, minimal set.
    $config->clear('member.types');
    $config->set('member.types.A', [
      'name' => 'Adult',
      'description' => 'Full adult membership.',
      'descriptionFormat' => 'basic_html',
      'price' => '30.00',
      'number_allowed' => 0,
      'badgeType' => 'A',
      'active' => 1,
      'allowFirst' => 1,
      'allowDuplicates' => FALSE,
      'defaultDays' => '',
    ]);
    $config->set('member.types.B', [
      'name' => 'Companion',
      'description' => '',
      'descriptionFormat' => 'basic_html',
      'price' => '10.00',
      'number_allowed' => 0,
      'badgeType' => 'A',
      'active' => 1,
      'allowFirst' => 0,
      'allowDuplicates' => FALSE,
      'defaultDays' => '',
    ]);
    $config->set('member.types.C', [
      'name' => 'Weekend',
      'description' => '',
      'descriptionFormat' => 'basic_html',
      'price' => '30.00',
      'number_allowed' => 0,
      'badgeType' => 'A',
      'active' => 1,
      'allowFirst' => 1,
      'allowDuplicates' => FALSE,
      'defaultDays' => 'W',
      'days' => [
        'Fr' => ['description' => 'Friday only', 'price' => '12.00'],
        'Sa' => ['description' => 'Saturday only', 'price' => '12.00'],
        'Su' => ['description' => 'Sunday only', 'price' => '12.00'],
      ],
    ]);
    $config->save();
  }

  /**
   * The member type field uses the member_type_cards element.
   */
  public function testMemberRegisterFormUsesCardsElementForType(): void {
    $this->createMemberTypesConfig();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_register');
    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $typeField = $form['members']['member1']['type'];
    $this->assertSame('member_type_cards', $typeField['#type']);
    $this->assertSame(['A', 'B', 'C'], array_keys($typeField['#options']));
    $this->assertSame(['A', 'B', 'C'], array_keys($typeField['#member_types']));
    $this->assertSame('Adult', $typeField['#member_types']['A']->name);
    $this->assertSame('€', $typeField['#currency_symbol']);
  }

  /**
   * Non-allowFirst types are shown but disabled, not hidden, for member 1.
   *
   * And not disabled at all for later members.
   */
  public function testMemberRegisterFormDisablesNonFirstTypesForMember1(): void {
    $this->createMemberTypesConfig();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_register');
    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $member1Type = $form['members']['member1']['type'];
    $this->assertArrayHasKey('B', $member1Type['#options']);
    $this->assertArrayHasKey('B', $member1Type['#disabled_options']);

    $formState = new FormState();
    $formState->setValues(['global' => ['member_quantity' => 2]]);
    $registration = $this->container->get('class_resolver')->getInstanceFromDefinition(Registration::class);
    $form = $registration->buildForm([], $formState, 1);

    $member2Type = $form['members']['member2']['type'];
    $this->assertArrayHasKey('B', $member2Type['#options']);
    $this->assertSame([], $member2Type['#disabled_options']);
  }

  /**
   * The memberTypes() labels use the type's name, not its description.
   *
   * Covers the case where remaining-count display is off.
   */
  public function testConregOptionsMemberTypesUsesNameNotDescriptionForLabelWithoutRemaining(): void {
    $this->createMemberTypesConfig();

    $types = ConregOptions::memberTypes(1);

    $this->assertSame('Adult', $types->publicOptions['A']);
    $this->assertSame('Adult', $types->firstOptions['A']);
  }

  /**
   * The same holds when remaining-count display is on.
   *
   * The label is still built from the type's name, not its description.
   */
  public function testConregOptionsMemberTypesUsesNameNotDescriptionForLabelWithRemaining(): void {
    $this->createMemberTypesConfig();
    $this->container->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('payments.show_remaining', TRUE)
      ->save();
    $this->container->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member.types.A.number_allowed', 10)
      ->save();

    $types = ConregOptions::memberTypes(1);
    $label = (string) $types->publicOptions['A'];

    $this->assertStringContainsString('Adult', $label);
    $this->assertStringNotContainsString('Full adult membership.', $label);
    $this->assertStringContainsString('remaining', $label);
  }

  /**
   * Builds a Registration form instance for direct method calls.
   */
  protected function createRegistrationForm(): Registration {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(Registration::class);
  }

  /**
   * A valid 'type' query parameter preselects that member type.
   */
  public function testMemberRegisterFormPreselectsTypeFromValidQueryParameter(): void {
    $this->createMemberTypesConfig();
    $this->container->get('request_stack')->getCurrentRequest()->query->set('type', 'C');

    $form = $this->createRegistrationForm()->buildForm([], new FormState(), 1);

    $this->assertSame('C', $form['members']['member1']['type']['#default_value']);
  }

  /**
   * An invalid 'type' query parameter is ignored when there's no config default.
   */
  public function testMemberRegisterFormIgnoresInvalidQueryParameterWithNoConfigDefault(): void {
    $this->createMemberTypesConfig();
    $this->container->get('request_stack')->getCurrentRequest()->query->set('type', 'not-a-real-code');

    $form = $this->createRegistrationForm()->buildForm([], new FormState(), 1);

    $this->assertArrayNotHasKey('#default_value', $form['members']['member1']['type']);
  }

  /**
   * An invalid 'type' query parameter falls back to the configured default.
   */
  public function testMemberRegisterFormFallsBackToConfigDefaultForInvalidQueryParameter(): void {
    $this->createMemberTypesConfig();
    $this->container->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member_type_default', 'A')
      ->save();
    $this->container->get('request_stack')->getCurrentRequest()->query->set('type', 'not-a-real-code');

    $form = $this->createRegistrationForm()->buildForm([], new FormState(), 1);

    $this->assertSame('A', $form['members']['member1']['type']['#default_value']);
  }

  /**
   * A valid 'type' query parameter only preselects the first member.
   *
   * Later members are unaffected by the query parameter, and with no config
   * default, get no type preselected at all.
   */
  public function testMemberRegisterFormDoesNotApplyQueryParameterToOtherMembers(): void {
    $this->createMemberTypesConfig();
    $this->container->get('request_stack')->getCurrentRequest()->query->set('type', 'C');

    $formState = new FormState();
    $formState->setValues(['global' => ['member_quantity' => 2]]);
    $form = $this->createRegistrationForm()->buildForm([], $formState, 1);

    $this->assertSame('C', $form['members']['member1']['type']['#default_value']);
    $this->assertArrayNotHasKey('#default_value', $form['members']['member2']['type']);
  }

  /**
   * Later members use the configured default type, not the query parameter.
   */
  public function testMemberRegisterFormOtherMembersUseConfigDefaultNotQueryParameter(): void {
    $this->createMemberTypesConfig();
    $this->container->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member_type_default', 'A')
      ->save();
    $this->container->get('request_stack')->getCurrentRequest()->query->set('type', 'C');

    $formState = new FormState();
    $formState->setValues(['global' => ['member_quantity' => 2]]);
    $form = $this->createRegistrationForm()->buildForm([], $formState, 1);

    $this->assertSame('C', $form['members']['member1']['type']['#default_value']);
    $this->assertSame('A', $form['members']['member2']['type']['#default_value']);
  }

  /**
   * A type with no days configured uses defaultDays and the full price.
   */
  public function testGetMemberPriceNoDaysUsesDefaultDaysAndFullPrice(): void {
    $this->createMemberTypesConfig();
    $types = ConregOptions::memberTypes(1)->types;

    $result = $this->createRegistrationForm()->getMemberPrice([], 1, $types, 0, 0, '$', 'A');

    $this->assertEquals(30.0, $result->price);
    $this->assertSame('', $result->days);
  }

  /**
   * A type with days configured, but none ticked, is unaffected.
   *
   * The full price and defaultDays are used, same as if it had no days at
   * all.
   */
  public function testGetMemberPriceNoDaysTickedUsesDefaultDaysAndFullPrice(): void {
    $this->createMemberTypesConfig();
    $types = ConregOptions::memberTypes(1)->types;

    $result = $this->createRegistrationForm()->getMemberPrice([], 1, $types, 0, 0, '$', 'C');

    $this->assertEquals(30.0, $result->price);
    $this->assertSame('W', $result->days);
  }

  /**
   * Ticking a proper subset of days, cheaper than the full price, discounts.
   */
  public function testGetMemberPriceSubsetOfDaysDiscountsPrice(): void {
    $this->createMemberTypesConfig();
    $types = ConregOptions::memberTypes(1)->types;
    $formValues = [
      'members' => [
        'member1' => [
          'type' => 'C',
          'dayOptions' => ['days' => ['Fr' => 1, 'Sa' => 1]],
        ],
      ],
    ];

    $result = $this->createRegistrationForm()->getMemberPrice($formValues, 1, $types, 0, 0, '$', 'C');

    $this->assertEquals(24.0, $result->price);
    $this->assertSame('Fr|Sa', $result->days);
    $this->assertSame('Friday, Saturday', $result->daysDesc);
  }

  /**
   * Ticking the whole-weekend checkbox does not override the full price.
   *
   * This pins down a real edge case in the current implementation: ticking
   * the single checkbox keyed by the member type itself sets the day price
   * equal to (not less than) the full price, and the override guard is
   * strictly "less than" - so it never fires, and the result is
   * indistinguishable from no days having been selected at all.
   */
  public function testGetMemberPriceWholeWeekendCheckboxDoesNotOverridePrice(): void {
    $this->createMemberTypesConfig();
    $types = ConregOptions::memberTypes(1)->types;
    $formValues = [
      'members' => [
        'member1' => [
          'type' => 'C',
          'dayOptions' => ['days' => ['C' => 1]],
        ],
      ],
    ];

    $result = $this->createRegistrationForm()->getMemberPrice($formValues, 1, $types, 0, 0, '$', 'C');

    $this->assertEquals(30.0, $result->price);
    $this->assertSame('W', $result->days);
  }

  /**
   * Days summing to at least the full price don't override it either.
   *
   * Same "strictly less than" guard as the whole-weekend case above.
   */
  public function testGetMemberPriceDaysSummingToFullPriceDoesNotOverride(): void {
    $this->createMemberTypesConfig();
    $types = ConregOptions::memberTypes(1)->types;
    $formValues = [
      'members' => [
        'member1' => [
          'type' => 'C',
          'dayOptions' => ['days' => ['Fr' => 1, 'Sa' => 1, 'Su' => 1]],
        ],
      ],
    ];

    $result = $this->createRegistrationForm()->getMemberPrice($formValues, 1, $types, 0, 0, '$', 'C');

    $this->assertEquals(30.0, $result->price);
    $this->assertSame('W', $result->days);
  }

  /**
   * The day-options checkboxes render when the selected type has day pricing.
   *
   * Using the same ajax callback as the type element.
   */
  public function testDayOptionsRenderedWhenSelectedTypeHasDayPricing(): void {
    $this->createMemberTypesConfig();
    $types = ConregOptions::memberTypes(1)->types;

    $formState = new FormState();
    $formState->setValues(['members' => ['member1' => ['type' => 'C']]]);
    $registration = $this->createRegistrationForm();
    $form = $registration->buildForm([], $formState, 1);

    $dayOptions = $form['members']['member1']['type']['day_options'];
    $this->assertSame('member_day_options', $dayOptions['#type']);
    $this->assertSame($types['C']->dayOptions, $dayOptions['#options']);
    $this->assertEquals($types['C']->days, $dayOptions['#day_data']);
    $this->assertSame([$registration, 'updateMemberPriceCallback'], $dayOptions['#ajax']['callback']);
    // Nested inside 'type' for rendering, but #parents keeps the submitted
    // value at the same values path as before, independent of where it's
    // nested in the render array.
    $this->assertSame(['members', 'member1', 'dayOptions', 'days'], $dayOptions['#parents']);
  }

  /**
   * The day-options checkboxes don't render for a type with no day pricing.
   */
  public function testDayOptionsNotRenderedWhenSelectedTypeHasNoDayPricing(): void {
    $this->createMemberTypesConfig();

    $formState = new FormState();
    $formState->setValues(['members' => ['member1' => ['type' => 'A']]]);
    $form = $this->createRegistrationForm()->buildForm([], $formState, 1);

    $this->assertArrayNotHasKey('day_options', $form['members']['member1']['type']);
  }

}
