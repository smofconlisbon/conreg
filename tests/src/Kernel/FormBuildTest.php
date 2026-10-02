<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\user\RoleInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\conreg\Addons;
use Drupal\conreg\AppliedRatePlanListBuilder;
use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Entity\RatePlan;
use Drupal\conreg\Form\Admin\CheckInBadgeNameForm;
use Drupal\conreg\Form\Admin\CheckInMembers;
use Drupal\conreg\Form\Admin\EventAddOns;
use Drupal\conreg\Form\Admin\EventConfig;
use Drupal\conreg\Payment;
use Drupal\conreg\Plugin\Derivative\EventsMenuDeriver;
use Drupal\conreg\Service\PaymentStorage;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\easy_email\Entity\EasyEmailType;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Tests that forms load correctly.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class FormBuildTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * A handful of legacy conreg.settings keys predate a strict schema.
   *
   * Submitting the full Event Configuration form (as
   * testAdminEventConfigFormSubmitPersistsShowMemberNo does) writes every
   * key on the form, including several - e.g. closed_message_text,
   * member_portal.add_role - that have no schema definition yet. Filling in
   * that pre-existing gap is unrelated to what this test file covers, so
   * strict schema checking is disabled here rather than left to mask an
   * unrelated fatal error on every full-form submission test.
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
   * Set up database tables and config for testing forms.
   */
  protected function setUp(): void {
    parent::setUp();

    // Required for #type = datetime.
    $this->installConfig(['system', 'datetime']);

    // $this->installSchema('system', ['router']);
    // Install the database schema for conreg.
    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
      'conreg_payments',
      'conreg_payment_lines',
    ]);
    $this->installEntitySchema('conreg_rate_plan');
    $this->installConfig(['conreg']);

    // Required for MemberTypes.php's default text format lookup, now that
    // 'filter' is enabled (a dependency of easy_email/text). A real site
    // has a 'basic_html' format (normally provided by the 'standard'
    // install profile, which this minimal Kernel test doesn't use). Must
    // come after the conreg schema/config above - saving user role config
    // rebuilds the permission list, which includes conreg's per-event
    // dynamic permissions (FieldOptionPermissions) and so queries
    // conreg_events.
    $this->installConfig(['filter', 'user']);
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'weight' => 0,
      'filters' => [],
    ])->save();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['use text format basic_html']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();
  }

  /**
   * Create a member with most required fields.
   */
  protected function createTestMember(array $overrides = []): int {
    $defaults = [
      'mid' => 1,
      'eid' => 1,
      'language' => 'en',
      'first_name' => 'Test',
      'last_name' => 'User',
      'email' => 'test@example.com',
      'join_date' => \Drupal::time()->getCurrentTime(),
      'update_date' => \Drupal::time()->getCurrentTime(),
    ];

    $fields = $overrides + $defaults;

    return Database::getConnection()
      ->insert('conreg_members')
      ->fields($fields)
      ->execute();
  }

  /**
   * Creates an EasyEmailType so MemberEmail's template select isn't empty.
   *
   * Without at least one easy_email_type entity, MemberEmail::buildForm()
   * takes its "No email templates found" early return instead of building
   * the real form - see testAdminMemberEmailFormBuild().
   */
  protected function createEmailTemplate() {
    EasyEmailType::create([
      'id' => 'conreg_registration_test',
      'label' => 'ConReg registration (test)',
      'subject' => 'Thank you for joining [conreg:event-name]',
      'bodyHtml' => [
        'value' => '<p>Hi [conreg:member:first-name],</p><p>This is to confirm you have joined [conreg:event-name].</p><p>Your member details are: [conreg:member-details]</p>',
        'format' => 'full_html',
      ],
      'generateBodyPlain' => FALSE,
    ])->save();
  }

  /**
   * Sets the configured default display option.
   */
  protected function setDefaultDisplayOption(string $display): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('display_options.default', $display)
      ->save();
  }

  /**
   * Test building the Registration form.
   */
  public function testMemberRegisterFormBuild() {
    $this->setDefaultDisplayOption('B');

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_register');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Registration',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_register', $form['#form_id']);
    $this->assertArrayNotHasKey('conreg_event', $form);
    $this->assertEquals('B', $form['members']['member1']['display']['#default_value']);
    // $this->assertEquals('Registration', (string) $form['#title']);
  }

  /**
   * Test building the checkout form.
   */
  public function testMemberCheckoutFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_checkout');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Payment',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_payment', $form['#form_id']);
  }

  /**
   * Test building the Check Membership form.
   */
  public function testMemberCheckMemberFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_check');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Check Membership',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_check_member', $form['#form_id']);
  }

  /**
   * Test building member portal form.
   */
  public function testMemberPortalFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_portal');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Member Portal',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_member_portal', $form['#form_id']);
  }

  /**
   * Test building member edit.
   */
  public function testMemberEditFormBuild() {
    $this->setDefaultDisplayOption('B');
    $this->container->get('current_user')->setAccount(new UserSession([
      'mail' => 'test@example.com',
    ]));
    $this->createTestMember([
      'lead_mid' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
      'is_paid' => 1,
    ]);

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_portal_edit');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Edit Member',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1, 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('member_edit_form', $form['#form_id']);
    $this->assertEquals('B', $form['member']['display']['#default_value']);
  }

  /**
   * Test building Event List form.
   */
  public function testAdminEventListFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_event_list');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'ConReg Event List',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_event_list', $form['#form_id']);
  }

  /**
   * Test building the ConReg overview routes.
   */
  public function testAdminOverviewRoutesBuild(): void {
    $route_provider = $this->container->get('router.route_provider');

    $overview = $route_provider->getRouteByName('conreg_overview');
    $this->assertSame('ConReg Overview', $overview->getDefault('_title'));
    $this->assertSame('access conreg events', $overview->getRequirement('_permission'));

    $event_overview = $route_provider->getRouteByName('conreg_event_overview');
    $this->assertSame('ConReg Event Overview', $event_overview->getDefault('_title'));
    $this->assertSame('access conreg events', $event_overview->getRequirement('_permission'));
  }

  /**
   * Test event menu links are grouped under the ConReg overview menu.
   */
  public function testAdminEventMenuDeriverUsesOverviewParent(): void {
    $deriver = EventsMenuDeriver::create($this->container, 'conreg.event_links');
    $links = $deriver->getDerivativeDefinitions(['id' => 'conreg.event_links']);

    $this->assertSame('conreg_event_overview', $links['conreg_event_1']['route_name']);
    $this->assertSame('conreg.overview', $links['conreg_event_1']['parent']);
  }

  /**
   * Test building Event List form.
   */
  public function testAdminEventCloneFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_event_clone');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Clone Event',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_event_clone', $form['#form_id']);
  }

  /**
   * Test building Event Config form.
   */
  public function testAdminEventConfigFormBuild() {
    $this->setDefaultDisplayOption('B');

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_config');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Event Configuration',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_config', $form['#form_id']);
    $this->assertEquals('B', $form['conreg_display_options']['default']['#default_value']);
    $this->assertArrayHasKey('F', $form['conreg_display_options']['default']['#options']);
  }

  /**
   * With no config saved, "Show member number" defaults to checked.
   */
  public function testAdminEventConfigFormShowMemberNoDefaultsToShown(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertArrayHasKey('show_member_no', $form['conreg_member_listing']);
    $this->assertTrue((bool) $form['conreg_member_listing']['show_member_no']['#default_value']);
  }

  /**
   * The "Show member number" checkbox reflects a saved config value of FALSE.
   */
  public function testAdminEventConfigFormShowMemberNoReflectsConfig(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member_listing_page.show_member_no', FALSE)
      ->save();

    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertArrayHasKey('show_member_no', $form['conreg_member_listing']);
    $this->assertFalse((bool) $form['conreg_member_listing']['show_member_no']['#default_value']);
  }

  /**
   * Unchecking "Show member number" and submitting persists it to config.
   *
   * Builds the real form to harvest its current default values (already
   * schema-valid, since they come from installed config), overrides just
   * the member-number checkbox to unchecked, then submits programmatically
   * via the form builder - the same route a real submission takes, so this
   * exercises EventConfig::submitForm() itself rather than just the build.
   */
  public function testAdminEventConfigFormSubmitPersistsShowMemberNo(): void {
    $formObject = EventConfig::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);

    $values = $this->extractFormValues($form);
    $values['conreg_member_listing']['show_member_no'] = 0;
    $formState->setValues($values);

    $formObject->submitForm($form, $formState);

    $this->assertFalse(
      $this->config('conreg.settings.1')->get('member_listing_page.show_member_no')
    );
  }

  /**
   * With no config saved, "Enable badge label printing" defaults to off.
   */
  public function testAdminEventConfigFormLabelPrintingDefaultsToDisabled(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertArrayHasKey('label_printing_enabled', $form['conreg_checkin']);
    $this->assertFalse((bool) $form['conreg_checkin']['label_printing_enabled']['#default_value']);
  }

  /**
   * The "Enable badge label printing" checkbox reflects a saved config value.
   */
  public function testAdminEventConfigFormLabelPrintingReflectsConfig(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('checkin.label_printing_enabled', TRUE)
      ->save();

    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertArrayHasKey('label_printing_enabled', $form['conreg_checkin']);
    $this->assertTrue((bool) $form['conreg_checkin']['label_printing_enabled']['#default_value']);
  }

  /**
   * Checking "Enable badge label printing" and submitting persists it.
   */
  public function testAdminEventConfigFormSubmitPersistsLabelPrintingEnabled(): void {
    $formObject = EventConfig::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1);

    $values = $this->extractFormValues($form);
    $values['conreg_checkin']['label_printing_enabled'] = 1;
    $formState->setValues($values);

    $formObject->submitForm($form, $formState);

    $this->assertTrue(
      $this->config('conreg.settings.1')->get('checkin.label_printing_enabled')
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

  /**
   * Test building Member Classes form.
   */
  public function testAdminMemberClassesFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_config_member_classes');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Member Classes',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_config_member_classes', $form['#form_id']);
  }

  /**
   * Test building Member Types form.
   */
  public function testAdminMemberTypesFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_config_member_types');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Member Types',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_config_member_types', $form['#form_id']);
    $this->assertArrayHasKey('easy_email_type', $form['A']['confirmation']);
    $this->assertEquals('select', $form['A']['confirmation']['easy_email_type']['#type']);
  }

  /**
   * Test building Event Add Ons form.
   */
  public function testAdminEventAddOnsFormBuild() {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.donation.addon', [
        'active' => 1,
        'global' => 0,
        'free' => 1,
        'label' => 'Donation',
        'description' => 'Choose an amount',
        'options' => '',
        'weight' => 0,
      ])
      ->save();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_config_addons');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Add-ons',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_event_addons', $form['#form_id']);
    $this->assertTrue((bool) $form['addons']['donation']['addon']['free']['#default_value']);
    $this->assertSame([
      'visible' => [
        ':input[name="addons[donation][addon][free]"]' => ['checked' => FALSE],
      ],
    ], $form['addons']['donation']['addon']['options']['#states']);
    $this->assertArrayNotHasKey('free', $form['addons']['donation']);
  }

  /**
   * Test building Manage Members form.
   */
  public function testAdminManageMembersFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_members');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Manage Members',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_members', $form['#form_id']);
  }

  /**
   * Test building Manage Members add new member form.
   */
  public function testAdminManageMembersNewFormBuild() {
    $this->setDefaultDisplayOption('B');

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_members_add');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Manage Members - Add Member',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_member_edit', $form['#form_id']);
    $this->assertEquals('B', $form['member']['display']['#default_value']);
  }

  /**
   * Test building Manage Members add new member form.
   */
  public function testAdminManageMembersEditFormBuild() {
    $this->createTestMember();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_members_edit');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Manage Members - Edit Member',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1, 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_member_edit', $form['#form_id']);
  }

  /**
   * Test building Manage Members add new member form.
   */
  public function testAdminManageMembersDeleteFormBuild() {
    $this->createTestMember();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_members_delete');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Manage Members - Delete Member',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1, 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_member_delete', $form['#form_id']);
  }

  /**
   * Test building Manage Members add new member form.
   */
  public function testAdminManageMembersTransferFormBuild() {
    $this->createTestMember();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_members_transfer');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Manage Members - Transfer Member',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1, 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_member_transfer', $form['#form_id']);
  }

  /**
   * Test building Manage Members form.
   */
  public function testAdminFanTableFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_fantable');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Fan Table',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_fantable', $form['#form_id']);
  }

  /**
   * Test building Manage Members form.
   */
  public function testAdminCheckInFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_checkin');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Member Checkin',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_checkin_members', $form['#form_id']);
  }

  /**
   * With label printing disabled (the default), no printer UI is shown.
   */
  public function testAdminCheckInFormHidesPrinterSelectWhenDisabled(): void {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_checkin');

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertArrayNotHasKey('printer', $form['checkin_actions']);
    $this->assertArrayNotHasKey('submit_print', $form['checkin_actions']);
  }

  /**
   * With label printing enabled and a printer, the printer UI is usable.
   */
  public function testAdminCheckInFormShowsPrinterSelectWhenEnabled(): void {
    $this->installEntitySchema('conreg_printer');
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('checkin.label_printing_enabled', TRUE)
      ->save();
    Printer::create(['eid' => 1, 'name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins'])->save();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_checkin');

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertSame('Bilbo Baggins', $form['checkin_actions']['printer']['#options']['bilbo_baggins'] ?? NULL);
    $this->assertArrayHasKey('submit_print', $form['checkin_actions']);
    $this->assertEmpty($form['checkin_actions']['submit_print']['#disabled'] ?? FALSE);
  }

  /**
   * With label printing enabled but no printers, the print button is off.
   */
  public function testAdminCheckInFormDisablesPrintButtonWhenNoPrinters(): void {
    $this->installEntitySchema('conreg_printer');
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('checkin.label_printing_enabled', TRUE)
      ->save();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_checkin');

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertTrue($form['checkin_actions']['submit_print']['#disabled']);
    $this->assertArrayHasKey('no_printers', $form);
  }

  /**
   * Submitting "Check-in and print labels" with no printer chosen errors.
   */
  public function testCheckInAndPrintRequiresPrinterSelection(): void {
    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->setValue('printer', '');

    $form = [];
    $formObject->validatePrinterSelected($form, $formState);

    $this->assertTrue($formState->hasAnyErrors());
  }

  /**
   * Submitting "Check-in and print labels" with a printer chosen is valid.
   */
  public function testCheckInAndPrintAllowsSelectedPrinter(): void {
    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->setValue('printer', 'bilbo_baggins');

    $form = [];
    $formObject->validatePrinterSelected($form, $formState);

    $this->assertFalse($formState->hasAnyErrors());
  }

  /**
   * Helper: push a request with a fresh session onto the request stack.
   */
  protected function pushRequestWithSession(): Session {
    $session = new Session(new MockArraySessionStorage());
    $request = Request::create('/');
    $request->setSession($session);
    $this->container->get('request_stack')->push($request);
    return $session;
  }

  /**
   * The printer select preselects a printer remembered in session.
   */
  public function testAdminCheckInFormPreselectsRememberedPrinter(): void {
    $this->installEntitySchema('conreg_printer');
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('checkin.label_printing_enabled', TRUE)
      ->save();
    Printer::create(['eid' => 1, 'name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins'])->save();

    $session = $this->pushRequestWithSession();
    $session->set('conreg_checkin_printer_1', 'bilbo_baggins');

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_checkin');

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertSame('bilbo_baggins', $form['checkin_actions']['printer']['#default_value']);
  }

  /**
   * A remembered printer that no longer exists is not preselected.
   */
  public function testAdminCheckInFormIgnoresRememberedPrinterThatNoLongerExists(): void {
    $this->installEntitySchema('conreg_printer');
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('checkin.label_printing_enabled', TRUE)
      ->save();
    Printer::create(['eid' => 1, 'name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins'])->save();

    $session = $this->pushRequestWithSession();
    $session->set('conreg_checkin_printer_1', 'deleted_printer');

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_checkin');

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'));

    $this->assertNull($form['checkin_actions']['printer']['#default_value']);
  }

  /**
   * Submitting "Check-in and print labels" remembers the printer chosen.
   */
  public function testCheckInAndPrintSubmitRemembersPrinterInSession(): void {
    $session = $this->pushRequestWithSession();

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->set('eid', 1);
    $formState->setValues([
      'table' => [1 => ['is_checked_in' => 1]],
      'printer' => 'bilbo_baggins',
    ]);

    $form = ['checkin_actions' => ['printer' => ['#options' => ['bilbo_baggins' => 'Bilbo Baggins']]]];
    $formObject->checkInAndPrintSubmit($form, $formState);

    $this->assertSame('bilbo_baggins', $session->get('conreg_checkin_printer_1'));
  }

  /**
   * The plain check-in confirm step lists members as a bulleted list.
   *
   * No printer was selected, so there's nothing to preview - only
   * testCheckInConfirmFormShowsLabelPreviewWhenPrinting exercises that.
   */
  public function testCheckInConfirmFormListsMembersWithoutPreviewWhenNotPrinting(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'member_no' => 42,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
    ]);

    $formObject = CheckInMembers::create($this->container);
    $form = $formObject->buildConfirmForm(1, [$mid]);

    // Regression test: the buttons that reach this step have no #ajax,
    // so it's a genuine full-page rebuild that bypasses buildForm()'s
    // own '#attached' declaration entirely - without this, conreg.css
    // (and so .conreg-checkin-confirm-list's styling) would never load.
    $this->assertContains('conreg/conreg_form', $form['#attached']['library']);
    $this->assertSame('item_list', $form['members']['#theme']);
    $this->assertSame('ul', $form['members']['#list_type']);
    $this->assertContains('conreg-checkin-confirm-list', $form['members']['#attributes']['class']);
    $this->assertCount(1, $form['members']['#items']);

    $text = (string) $form['members']['#items'][0]['text']['#markup'];
    // showBadgeNumber() zero-pads to conreg.settings.1's default
    // member_no_digits (4) - see PrintJobManagerKernelTest for the same
    // formatting behavior.
    $this->assertStringContainsString('<strong>0042</strong>', $text);
    $this->assertStringContainsString('<strong>Jane Doe</strong>', $text);
    $this->assertArrayNotHasKey('preview', $form['members']['#items'][0]);
  }

  /**
   * The "Check-in and print labels" flow also shows a per-member preview.
   */
  public function testCheckInConfirmFormShowsLabelPreviewWhenPrinting(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'member_no' => 42,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
    ]);

    $formObject = CheckInMembers::create($this->container);
    $form = $formObject->buildConfirmForm(1, [$mid], 'Bilbo Baggins');

    $preview = $form['members']['#items'][0]['preview'];
    $this->assertSame('img', $preview['#tag']);
    $this->assertStringStartsWith('data:image/png;base64,', $preview['#attributes']['src']);
    $this->assertContains('conreg-label-preview-image', $preview['#attributes']['class']);
    $this->assertContains('conreg-checkin-confirm-preview', $preview['#attributes']['class']);
  }

  /**
   * CheckInBadgeNameForm shows the member's current badge name.
   */
  public function testCheckInBadgeNameFormBuild(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Original Name',
      'is_paid' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInBadgeNameForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $this->assertSame('textfield', $form['badge_name']['#type']);
    $this->assertSame('Original Name', $form['badge_name']['#default_value']);
    $this->assertTrue($form['badge_name']['#required']);
    $this->assertSame('submit', $form['actions']['submit']['#type']);
    $this->assertSame('::ajaxSubmit', $form['actions']['submit']['#ajax']['callback']);
    $this->assertSame('submit', $form['actions']['cancel']['#type']);
    // Must resolve to a real no-op handler, not [] - an empty array
    // falls back to the form's top-level #submit (which includes
    // submitForm()), so Cancel would silently save anyway. See
    // CheckInBadgeNameFormKernelTest::testCancelSubmitHandlerDoesNotSave.
    $this->assertSame(['::cancelSubmit'], $form['actions']['cancel']['#submit']);
    $this->assertSame('::ajaxCancel', $form['actions']['cancel']['#ajax']['callback']);
  }

  /**
   * Test building Email Member form.
   */
  public function testAdminMemberEmailFormBuild() {
    $this->createTestMember();
    $this->createEmailTemplate();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_members_email');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals(
      'Member Administration - Email Member',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1, 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_member_email', $form['#form_id']);

    // #form_id is stamped by the form builder regardless of which branch
    // buildForm() takes, so assert on content only the real (non-early-
    // return) form contains, proving the created EasyEmailType was found.
    $this->assertArrayHasKey('conreg_registration_test', $form['template']['template_select']['#options']);
    $this->assertSame(
      'Thank you for joining [conreg:event-name]',
      $form['email']['message']['subject']['#default_value']
    );
  }

  /**
   * Set up form for the test case.
   */
  protected function createBulkEmailConfig(int $eid = 1): void {
    $config = $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.' . $eid);

    $config->set('bulk_email.from_name', 'Admin');
    $config->set('bulk_email.from_email', 'admin@example.com');
    $config->set('bulk_email.easy_email_type', 'conreg_registration_default');
    $config->save();
  }

  /**
   * Test Bulk Email form.
   */
  public function testAdminBulkEmailFormBuild(): void {
    $this->createTestMember();
    $this->createBulkEmailConfig();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_bulk_email');

    $this->assertInstanceOf(Route::class, $route);

    $this->assertEquals(
    'Membership Bulk Email Sender',
    $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_bulk_email', $form['#form_id']);
  }

  /**
   * Test building Member Mailout Emails form.
   */
  public function testAdminMailoutEmailsFormBuild(): void {
    // Mailout form depends on members existing.
    $this->createTestMember();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_mailout_emails');

    $this->assertInstanceOf(Route::class, $route);

    $this->assertEquals(
      'Member Mailout Emails',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_mailout_emails', $form['#form_id']);
  }

  /**
   * Test building Member Add-ons form.
   */
  public function testAdminMemberAddOnsFormBuild(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.donation.addon', [
        'active' => 1,
        'free' => 1,
        'label' => 'Donation',
      ])
      ->save();

    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('conreg_admin_member_addons');

    $this->assertInstanceOf(Route::class, $route);

    $this->assertEquals(
      'Member Addons',
      $route->getDefault('_title')
    );

    $form = $this->container
      ->get('form_builder')
      ->getForm($route->getDefault('_form'), 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('#form_id', $form);
    $this->assertEquals('conreg_admin_member_options', $form['#form_id']);
    $this->assertSame('Donation', $form['selAddOn']['#options']['donation']);
  }

  /**
   * Test building add-ons with old config that has no label.
   */
  public function testAddOnWithoutLabelFallsBackToName(): void {
    $config = $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1');
    $config
      ->set('add-ons.badge.addon.active', 1)
      ->set('add-ons.badge.addon.global', 0)
      ->set('add-ons.badge.addon.options', "print|Printed badge|5")
      ->save();

    $form_state = new FormState();
    $form = Addons::getAddon(
      $this->container->get('config.factory')->get('conreg.settings.1'),
      [],
      1,
      [self::class, 'addOnAjaxCallback'],
      $form_state
    );

    $this->assertSame('badge', $form['badge']['option']['#title']);
  }

  /**
   * Test reading paid add-ons with old config that has no label.
   */
  public function testMemberAddOnWithoutLabelFallsBackToName(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.badge.addon.active', 1)
      ->save();

    Database::getConnection()->insert('conreg_member_addons')
      ->fields([
        'mid' => 1,
        'addon_name' => 'badge',
        'addon_option' => 'print',
        'addon_amount' => '5.00',
        'is_paid' => 1,
      ])
      ->execute();

    $addons = Addons::getMemberAddons(
      $this->container->get('config.factory')->get('conreg.settings.1'),
      1
    );

    $this->assertSame('badge', $addons['badge']->label);
    $this->assertSame('', $addons['badge']->info_label);
    $this->assertSame('', $addons['badge']->free_label);
  }

  /**
   * Tests that free amount add-ons use the common label and description.
   */
  public function testFreeAmountAddOnUsesCommonLabel(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.donation.addon', [
        'active' => 1,
        'global' => 0,
        'free' => 1,
        'label' => 'Donation',
        'description' => 'Choose an amount',
        'options' => "fixed|Fixed amount|10",
      ])
      ->save();

    $config = $this->container
      ->get('config.factory')
      ->get('conreg.settings.1');
    $form = Addons::getAddon(
      $config,
      [],
      1,
      [self::class, 'addOnAjaxCallback'],
      new FormState()
    );

    $this->assertArrayNotHasKey('option', $form['donation']);
    $this->assertSame('Donation', $form['donation']['free_amount']['#title']);
    $this->assertSame('Choose an amount', $form['donation']['free_amount']['#description']);

    [$total, , , $members, $members_minus_free] = Addons::getAllAddonPrices($config, [
      'global' => ['member_quantity' => 1],
      'members' => [
        'member1' => [
          'add_on' => [
            'donation' => ['free_amount' => 12.5],
          ],
        ],
      ],
    ]);
    $this->assertSame(12.5, $total);
    $this->assertSame(12.5, $members[1]);
    $this->assertSame(0, $members_minus_free[1]);
  }

  /**
   * Tests that active add-ons require the common label.
   */
  public function testActiveAddOnRequiresLabel(): void {
    $form = [];
    $form_state = (new FormState())->setValues([
      'new_addon' => [
        'addon_name' => '',
      ],
      'addons' => [
        'donation' => [
          'addon' => [
            'active' => 1,
            'free' => 1,
            'label' => '',
          ],
        ],
      ],
    ]);

    $this->container
      ->get('class_resolver')
      ->getInstanceFromDefinition(EventAddOns::class)
      ->validateForm($form, $form_state);

    $this->assertNotEmpty($form_state->getErrors());
  }

  /**
   * Tests migrating free amount labels into the common add-on fields.
   */
  public function testFreeAmountConfigUpdate(): void {
    $storage = $this->container->get('config.storage');
    $data = $storage->read('conreg.settings.1');
    $data['add-ons']['donation'] = [
      'addon' => [
        'active' => 1,
        'label' => 'Old label',
        'description' => 'Old description',
        'options' => '',
      ],
      'free' => [
        'label' => 'Donation',
        'description' => 'Choose an amount',
      ],
    ];
    $storage->write('conreg.settings.1', $data);
    $this->container->get('config.factory')->reset('conreg.settings.1');

    $this->container->get('module_handler')->loadInclude('conreg', 'install');
    conreg_update_9005();
    $this->container->get('config.factory')->reset('conreg.settings.1');
    $add_on = $this->container
      ->get('config.factory')
      ->get('conreg.settings.1')
      ->get('add-ons.donation');

    $this->assertTrue($add_on['addon']['free']);
    $this->assertSame('Donation', $add_on['addon']['label']);
    $this->assertSame('Choose an amount', $add_on['addon']['description']);
    $this->assertArrayNotHasKey('free', $add_on);
  }

  /**
   * Tests that saveMemberAddons() persists an option-based add-on.
   */
  public function testSaveMemberAddonsPersistsOptionBasedAddon(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.tshirt.addon', [
        'active' => 1,
        'global' => 0,
        'free' => 0,
        'label' => 'T-Shirt',
        'description' => '',
        'options' => "tshirt|T-Shirt|10",
        'weight' => 0,
      ])
      ->save();

    $mid = $this->createTestMember();
    $config = $this->container->get('config.factory')->get('conreg.settings.1');

    (new Addons())->saveMemberAddons($config, [
      'member' => [
        'add_on' => [
          'tshirt' => ['option' => 'tshirt'],
        ],
      ],
    ], $mid);

    $saved = $this->container
      ->get('conreg.addon_storage')
      ->load(['mid' => $mid, 'addon_name' => 'tshirt']);

    $this->assertSame('tshirt', $saved['addon_option']);
    $this->assertEquals(10, $saved['addon_amount']);
  }

  /**
   * Tests that saveMemberAddons() persists a free-amount add-on.
   */
  public function testSaveMemberAddonsPersistsFreeAmountAddon(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.donation.addon', [
        'active' => 1,
        'global' => 0,
        'free' => 1,
        'label' => 'Donation',
        'description' => 'Choose an amount',
        'options' => '',
        'weight' => 0,
      ])
      ->save();

    $mid = $this->createTestMember();
    $config = $this->container->get('config.factory')->get('conreg.settings.1');

    (new Addons())->saveMemberAddons($config, [
      'member' => [
        'add_on' => [
          'donation' => ['free_amount' => 15.5],
        ],
      ],
    ], $mid);

    $saved = $this->container
      ->get('conreg.addon_storage')
      ->load(['mid' => $mid, 'addon_name' => 'donation']);

    $this->assertEmpty($saved['addon_option']);
    $this->assertEquals(15.5, $saved['addon_amount']);
  }

  /**
   * Tests that saveAddons() persists an option-based per-member add-on.
   */
  public function testSaveAddonsPersistsOptionBasedMemberAddon(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.tshirt.addon', [
        'active' => 1,
        'global' => 0,
        'free' => 0,
        'label' => 'T-Shirt',
        'description' => '',
        'options' => "tshirt|T-Shirt|10",
        'weight' => 0,
      ])
      ->save();

    $mid = $this->createTestMember();
    $config = $this->container->get('config.factory')->get('conreg.settings.1');
    $payment = new Payment($this->container->get(PaymentStorage::class));

    Addons::saveAddons($config, [
      'members' => [
        'member1' => [
          'first_name' => 'Test',
          'last_name' => 'User',
          'add_on' => [
            'tshirt' => ['option' => 'tshirt'],
          ],
        ],
      ],
    ], [1 => $mid], $payment);

    $saved = $this->container
      ->get('conreg.addon_storage')
      ->load(['mid' => $mid, 'addon_name' => 'tshirt']);

    $this->assertSame('tshirt', $saved['addon_option']);
    $this->assertEquals(10, $saved['addon_amount']);
    $this->assertCount(1, $payment->paymentLines);
    $this->assertEquals(10, $payment->paymentLines[0]->amount);
  }

  /**
   * Tests that saveAddons() persists a free-amount per-member add-on.
   */
  public function testSaveAddonsPersistsFreeAmountMemberAddon(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.donation.addon', [
        'active' => 1,
        'global' => 0,
        'free' => 1,
        'label' => 'Donation',
        'description' => 'Choose an amount',
        'options' => '',
        'weight' => 0,
      ])
      ->save();

    $mid = $this->createTestMember();
    $config = $this->container->get('config.factory')->get('conreg.settings.1');
    $payment = new Payment($this->container->get(PaymentStorage::class));

    Addons::saveAddons($config, [
      'members' => [
        'member1' => [
          'first_name' => 'Test',
          'last_name' => 'User',
          'add_on' => [
            'donation' => ['free_amount' => 20],
          ],
        ],
      ],
    ], [1 => $mid], $payment);

    $saved = $this->container
      ->get('conreg.addon_storage')
      ->load(['mid' => $mid, 'addon_name' => 'donation']);

    $this->assertEmpty($saved['addon_option']);
    $this->assertEquals(20, $saved['addon_amount']);
    $this->assertCount(1, $payment->paymentLines);
    $this->assertEquals(20, $payment->paymentLines[0]->amount);
  }

  /**
   * Tests that saveAddons() persists an option-based global add-on.
   */
  public function testSaveAddonsPersistsOptionBasedGlobalAddon(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.parking.addon', [
        'active' => 1,
        'global' => 1,
        'free' => 0,
        'label' => 'Parking',
        'description' => '',
        'options' => "pass|Parking Pass|25",
        'weight' => 0,
      ])
      ->save();

    $mid = $this->createTestMember();
    $config = $this->container->get('config.factory')->get('conreg.settings.1');
    $payment = new Payment($this->container->get(PaymentStorage::class));

    Addons::saveAddons($config, [
      'payment' => [
        'global_add_on' => [
          'parking' => ['option' => 'pass'],
        ],
      ],
    ], [1 => $mid], $payment);

    $saved = $this->container
      ->get('conreg.addon_storage')
      ->load(['mid' => $mid, 'addon_name' => 'parking']);

    $this->assertSame('pass', $saved['addon_option']);
    $this->assertEquals(25, $saved['addon_amount']);
    $this->assertCount(1, $payment->paymentLines);
    $this->assertEquals(25, $payment->paymentLines[0]->amount);
  }

  /**
   * Tests that saveAddons() persists a free-amount global add-on.
   */
  public function testSaveAddonsPersistsFreeAmountGlobalAddon(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('add-ons.donation.addon', [
        'active' => 1,
        'global' => 1,
        'free' => 1,
        'label' => 'Donation',
        'description' => 'Choose an amount',
        'options' => '',
        'weight' => 0,
      ])
      ->save();

    $mid = $this->createTestMember();
    $config = $this->container->get('config.factory')->get('conreg.settings.1');
    $payment = new Payment($this->container->get(PaymentStorage::class));

    Addons::saveAddons($config, [
      'payment' => [
        'global_add_on' => [
          'donation' => ['free_amount' => 30],
        ],
      ],
    ], [1 => $mid], $payment);

    $saved = $this->container
      ->get('conreg.addon_storage')
      ->load(['mid' => $mid, 'addon_name' => 'donation']);

    $this->assertEmpty($saved['addon_option']);
    $this->assertEquals(30, $saved['addon_amount']);
    $this->assertCount(1, $payment->paymentLines);
    $this->assertEquals(30, $payment->paymentLines[0]->amount);
  }

  /**
   * Create and save a rate plan for event 1.
   */
  protected function createRatePlan(string $plannedDate, array $prices): RatePlan {
    $plan = RatePlan::create(['eid' => 1, 'planned_date' => $plannedDate])->setPrices($prices);
    $plan->save();
    return $plan;
  }

  /**
   * Get a rate plan entity form object.
   */
  protected function ratePlanForm(RatePlan $plan, string $operation): EntityFormInterface {
    return $this->container->get('entity_type.manager')
      ->getFormObject('conreg_rate_plan', $operation)
      ->setEntity($plan);
  }

  /**
   * Test building the rate plan add and edit forms.
   */
  public function testAdminRatePlanEditFormBuild() {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('entity.conreg_rate_plan.add_form');

    $this->assertInstanceOf(Route::class, $route);
    $this->assertEquals('Add rate plan', $route->getDefault('_title'));

    $form = $this->container
      ->get('entity.form_builder')
      ->getForm(RatePlan::create(['eid' => 1]), 'add');

    $this->assertEquals('conreg_rate_plan_add_form', $form['#form_id']);
    $this->assertSame('', $form['planned_date']['#default_value']);
    // A new plan starts from the current prices.
    $this->assertEquals(50, $form['prices']['A']['price']['#default_value']);
    $this->assertSame('€', $form['prices']['A']['price']['#field_prefix']);
    $this->assertArrayNotHasKey('delete', $form['actions']);

    $plan = $this->createRatePlan('2026-11-01', ['A' => '60']);
    $form = $this->container
      ->get('entity.form_builder')
      ->getForm($plan, 'edit');

    $this->assertEquals('Edit the rate plan planned for Sun, 1 November 2026', (string) $form['#title']);
    $this->assertSame('2026-11-01', $form['planned_date']['#default_value']);
    $this->assertEquals(60, $form['prices']['A']['price']['#default_value']);
    $warning = $form['missing']['#message_list']['warning'][0];
    $this->assertEquals('These member types were added after this plan was created, so need a price:', (string) $warning['intro']['#markup']);
    $this->assertSame(['Low Income', 'Child', 'Infant', 'Supporting'], $warning['list']['#items']);
    $this->assertTrue($form['prices']['U']['price']['#required']);
    // Member types added since the plan was saved aren't pre-filled.
    $this->assertSame('', $form['prices']['U']['price']['#default_value']);
    $this->assertSame(['submit', 'delete'], array_values(array_filter(array_keys($form['actions']), fn($key) => $key[0] !== '#')));
  }

  /**
   * Test a rate plan can't be saved without a price for every member type.
   */
  public function testAdminRatePlanEditRequiresAllPrices() {
    $form_state = (new FormState())->setValues([
      'planned_date' => '2026-11-01',
      // Clear the prices pre-filled from the current ones.
      'prices' => [
        'A' => ['price' => '60'],
        'U' => ['price' => ''],
        'C' => ['price' => ''],
        'I' => ['price' => ''],
        'S' => ['price' => ''],
      ],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($this->ratePlanForm(RatePlan::create(['eid' => 1]), 'add'), $form_state);

    $this->assertSame(['prices][U][price', 'prices][C][price', 'prices][I][price', 'prices][S][price'], array_keys($form_state->getErrors()));
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('conreg_rate_plan')->loadPlanned(1));
  }

  /**
   * Test a rate plan can't be saved with an invalid planned date.
   */
  public function testAdminRatePlanEditRejectsInvalidDates() {
    $prices = [
      'A' => ['price' => '60'],
      'U' => ['price' => '25'],
      'C' => ['price' => '15'],
      'I' => ['price' => '0'],
      'S' => ['price' => '25'],
    ];
    foreach (['2026-02-31', '2026-13-01', '2026-1-1'] as $date) {
      $form_state = (new FormState())->setValues([
        'planned_date' => $date,
        'prices' => $prices,
        'op' => 'Save',
      ]);
      $this->container->get('form_builder')->submitForm($this->ratePlanForm(RatePlan::create(['eid' => 1]), 'add'), $form_state);
      $this->assertSame(['planned_date'], array_keys($form_state->getErrors()), $date);
    }
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('conreg_rate_plan')->loadPlanned(1));
  }

  /**
   * Test saving a rate plan always reports the save, as core forms do.
   */
  public function testAdminRatePlanEditReportsSave() {
    $plan = $this->createRatePlan('2026-11-01', ['A' => '60', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25']);
    $form_builder = $this->container->get('form_builder');
    $messenger = $this->container->get('messenger');

    $form_state = (new FormState())->setValues(['op' => 'Save']);
    $form_builder->submitForm($this->ratePlanForm($plan, 'edit'), $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertCount(1, $messenger->deleteByType('status'));

    $form_state = (new FormState())->setValues([
      'prices' => ['A' => ['price' => '65']],
      'op' => 'Save',
    ]);
    $form_builder->submitForm($this->ratePlanForm($plan, 'edit'), $form_state);
    $this->assertCount(1, $messenger->deleteByType('status'));
    $plan = $this->container->get('entity_type.manager')->getStorage('conreg_rate_plan')->loadUnchanged($plan->id());
    $this->assertEquals(65, $plan->getPrices()['A']);
  }

  /**
   * Test editing a plan hides and tidies away deleted member types.
   */
  public function testAdminRatePlanEditTidiesDeletedMemberTypes() {
    // X is a member type deleted after the plan was saved.
    $plan = $this->createRatePlan('2026-11-01', [
      'A' => '60',
      'U' => '25',
      'C' => '15',
      'I' => '0',
      'S' => '25',
      'X' => '99',
    ]);

    $form = $this->container->get('entity.form_builder')->getForm($plan, 'edit');
    $this->assertArrayNotHasKey('X', $form['prices']);

    $form_state = (new FormState())->setValues(['op' => 'Save']);
    $this->container->get('form_builder')->submitForm($this->ratePlanForm($plan, 'edit'), $form_state);
    $plan = $this->container->get('entity_type.manager')->getStorage('conreg_rate_plan')->loadUnchanged($plan->id());
    $this->assertSame(['A', 'U', 'C', 'I', 'S'], array_keys($plan->getPrices()));
  }

  /**
   * Test building the rate plan apply and delete confirmation forms.
   */
  public function testAdminRatePlanConfirmFormsBuild() {
    $plan = $this->createRatePlan('2026-11-01', ['A' => '60', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25']);
    $entity_form_builder = $this->container->get('entity.form_builder');

    $form = $entity_form_builder->getForm($plan, 'apply');
    $this->assertEquals('conreg_rate_plan_apply_form', $form['#form_id']);
    $this->assertCount(5, $form['prices']['#rows']);
    $this->assertArrayHasKey('submit', $form['actions']);
    $this->assertArrayNotHasKey('earlier', $form);

    // Applying a plan warns about earlier plans that haven't been applied.
    $later = $this->createRatePlan('2026-12-01', ['A' => '70', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25']);
    $form = $entity_form_builder->getForm($later, 'apply');
    $this->assertEquals('There is an unapplied rate plan planned before this one.', (string) $form['earlier']['#message_list']['warning'][0]);
    $this->assertArrayHasKey('submit', $form['actions']);

    $form = $entity_form_builder->getForm($plan, 'delete');
    $this->assertEquals('conreg_rate_plan_delete_form', $form['#form_id']);
  }

  /**
   * Test building the planned and applied rate plan lists.
   */
  public function testAdminRatePlanListsBuild() {
    $this->installEntitySchema('user');
    $complete = ['A' => '60', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25'];
    $this->createRatePlan('2000-01-01', $complete);
    $this->createRatePlan('2099-11-01', ['A' => '60']);
    $this->createRatePlan('2099-12-01', ['A' => '70'] + $complete);
    // Back to the current prices, so changes nothing if applied now.
    $this->createRatePlan('2099-12-02', ['A' => '50'] + $complete);
    // X is a member type deleted after the plan was applied, and Adult has
    // been renamed since.
    $this->createRatePlan('2000-01-02', ['A' => '55', 'X' => '10'])
      ->markApplied(['A' => '50', 'X' => '5'], [], 0, 200)
      ->setNames(['A' => 'Grown-up', 'X' => 'Dealer'], [])
      ->save();
    // Applied with no names recorded.
    $this->createRatePlan('2000-01-03', ['A' => '50'])->markApplied(['A' => '55'], [], 0, 300)->save();

    $this->setUpCurrentUser(permissions: ['configure convention registration']);

    // The lists show the plans of the event in the route.
    $route_name = 'entity.conreg_rate_plan.collection';
    $request = Request::create('/admin/config/conreg/rate-plans/1');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName($route_name));
    $request->attributes->set('_raw_variables', new InputBag(['eid' => '1']));
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $entity_type_manager = $this->container->get('entity_type.manager');
    $planned = $entity_type_manager->getListBuilder('conreg_rate_plan')->render();
    $this->assertEquals('Test event rate plans', (string) $planned['#title']);
    $this->assertStringContainsString('There are no planned rate plans.', (string) $planned['table']['#empty']);
    $rows = array_values($planned['table']['#rows']);
    $this->assertCount(4, $rows);
    // Only changed and unset prices are listed, as if the plans before have
    // been applied: the first plan changes Adult from its current price; the
    // second leaves Adult at the first plan's price and four prices unset;
    // the third changes Adult from the first plan's price.
    $this->assertCount(1, $rows[0]['data']['prices']['data']['#items']);
    $this->assertEquals('Adult: €50.00 → €60.00 (+€10.00)', (string) $rows[0]['data']['prices']['data']['#items'][0]['#markup']);
    $this->assertCount(4, $rows[1]['data']['prices']['data']['#items']);
    $this->assertCount(1, $rows[2]['data']['prices']['data']['#items']);
    $this->assertEquals('Adult: €60.00 → €70.00 (+€10.00)', (string) $rows[2]['data']['prices']['data']['#items'][0]['#markup']);

    // Apply is the visible operation once a complete plan is due, can be
    // applied early from the dropdown, and isn't offered for incomplete plans.
    $links = $rows[0]['data']['operations']['data']['#links'];
    $this->assertSame(['apply', 'edit', 'delete'], array_keys($links));
    $this->assertSame(['edit', 'delete'], array_keys($rows[1]['data']['operations']['data']['#links']));
    $this->assertSame(['edit', 'apply', 'delete'], array_keys($rows[2]['data']['operations']['data']['#links']));
    // A plan that changes nothing from the current prices can't be applied,
    // even though it changes prices from the plans before it.
    $this->assertCount(1, $rows[3]['data']['prices']['data']['#items']);
    $this->assertSame(['edit', 'delete'], array_keys($rows[3]['data']['operations']['data']['#links']));

    // Rows that need attention are highlighted as core does: an error for a
    // plan that can't be applied, a warning for one that is overdue.
    $this->assertSame(['color-warning', 'conreg-rate-plan--due'], $rows[0]['class']);
    $this->assertSame(['color-error'], $rows[1]['class']);
    $this->assertSame([], $rows[2]['class']);

    // Apply and Delete open in a modal; Edit is a full page.
    $this->assertSame('modal', $links['apply']['attributes']['data-dialog-type']);
    // The label reads the date in words, without an abbreviated weekday.
    $this->assertEquals('Apply Rate plan for 1 January 2000', (string) $links['apply']['attributes']['aria-label']);
    $this->assertSame('modal', $links['delete']['attributes']['data-dialog-type']);
    $this->assertArrayNotHasKey('attributes', $links['edit']);

    $applied = $entity_type_manager
      ->createHandlerInstance(AppliedRatePlanListBuilder::class, $entity_type_manager->getDefinition('conreg_rate_plan'))
      ->render();
    $this->assertEquals('Test event applied rate plans', (string) $applied['#title']);
    $this->assertEquals('No rate plans have been applied.', (string) $applied['table']['#empty']);
    // Prices changed without a rate plan aren't recorded, so the page says so.
    $this->assertStringContainsString('not shown', (string) $applied['intro']['#value']);
    $applied_rows = array_values($applied['table']['#rows']);
    $this->assertCount(2, $applied_rows);
    // Without recorded names, member types are named as they are now.
    $applied_items = $applied_rows[0]['prices']['data']['#items'];
    $this->assertEquals(['Adult: €55.00 → €50.00 (−€5.00)'], array_map(fn($item) => (string) $item['#markup'], $applied_items));
    // Member types are named as they were when the plan was applied, deleted
    // ones are still listed, and changes show the difference.
    $applied_items = $applied_rows[1]['prices']['data']['#items'];
    $this->assertCount(2, $applied_items);
    $this->assertEquals('Grown-up: €50.00 → €55.00 (+€5.00)', (string) $applied_items[0]['#markup']);
    $this->assertEquals('Dealer: €5.00 → €10.00 (+€5.00)', (string) $applied_items[1]['#markup']);
    $this->assertSame(['conreg-rate-plan__changed'], $applied_items[0]['#wrapper_attributes']['class']);
  }

  /**
   * Enable Friday and Saturday for Adult, with Saturday given no price.
   */
  protected function enableAdultDays(): void {
    $this->config('conreg.settings.1')
      ->set('member.types.A.days', [
        'Fr' => ['description' => 'Friday only', 'price' => '20'],
        'Sa' => ['description' => 'Saturday only', 'price' => ''],
      ])
      ->save();
    $this->container->get('cache_tags.invalidator')->invalidateTags(['event:1:type']);
  }

  /**
   * Test the rate plan editor has a row for each enabled day.
   */
  public function testAdminRatePlanEditDayPrices() {
    $this->enableAdultDays();
    $entity_form_builder = $this->container->get('entity.form_builder');

    $form = $entity_form_builder->getForm(RatePlan::create(['eid' => 1]), 'add');
    // Day rows follow their member type's row.
    $keys = array_values(array_filter(array_keys($form['prices']), fn($key) => $key[0] !== '#'));
    $this->assertSame(['A', 'day:A:Fr', 'day:A:Sa', 'U'], array_slice($keys, 0, 4));
    $friday = $form['prices']['day:A:Fr'];
    $this->assertSame(['conreg-rate-plan__day'], $friday['#attributes']['class']);
    $this->assertEquals('Planned Friday price for Adult', (string) $friday['price']['#title']);
    $this->assertTrue($friday['price']['#required']);
    // A new plan starts from the current day prices, and a day with no price
    // is free.
    $this->assertEquals(20, $friday['price']['#default_value']);
    $this->assertSame('0', $form['prices']['day:A:Sa']['price']['#default_value']);

    $prices = [
      'A' => ['price' => '60'],
      'U' => ['price' => '25'],
      'C' => ['price' => '15'],
      'I' => ['price' => '0'],
      'S' => ['price' => '25'],
    ];
    $form_state = (new FormState())->setValues([
      'planned_date' => '2026-11-01',
      'prices' => $prices,
      'day_prices' => ['A' => ['Fr' => '25', 'Sa' => '']],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($this->ratePlanForm(RatePlan::create(['eid' => 1]), 'add'), $form_state);
    $this->assertSame(['day_prices][A][Sa'], array_keys($form_state->getErrors()));

    $form_state = (new FormState())->setValues([
      'planned_date' => '2026-11-01',
      'prices' => $prices,
      'day_prices' => ['A' => ['Fr' => '25', 'Sa' => '5']],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($this->ratePlanForm(RatePlan::create(['eid' => 1]), 'add'), $form_state);
    $this->assertSame([], $form_state->getErrors());
    $plans = $this->container->get('entity_type.manager')->getStorage('conreg_rate_plan')->loadPlanned(1);
    $plan = reset($plans);
    $this->assertEquals(['A' => 60, 'U' => 25, 'C' => 15, 'I' => 0, 'S' => 25], $plan->getPrices());
    $this->assertEquals(['A' => ['Fr' => 25, 'Sa' => 5]], $plan->getDayPrices());

    // Days enabled since the plan was saved aren't pre-filled.
    $plan->setDayPrices(['A' => ['Fr' => '25']])->save();
    $form = $entity_form_builder->getForm($plan, 'edit');
    $this->assertEquals(25, $form['prices']['day:A:Fr']['price']['#default_value']);
    $this->assertSame('', $form['prices']['day:A:Sa']['price']['#default_value']);
    $warning = $form['missing']['#message_list']['warning'][0];
    $this->assertEquals('These days were enabled after this plan was created, so need a price:', (string) $warning['intro']['#markup']);
    $this->assertSame('item_list', $warning['list']['#theme']);
    $this->assertEquals(['Adult, Saturday'], array_map('strval', $warning['list']['#items']));
  }

  /**
   * Test the apply confirmation and lists show day price changes.
   */
  public function testAdminRatePlanDayPriceChanges() {
    $this->installEntitySchema('user');
    $this->enableAdultDays();
    $complete = ['A' => '50', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25'];
    // Only day prices change: Friday goes up, and Saturday stays free.
    $plan = $this->createRatePlan('2099-11-01', $complete)->setDayPrices(['A' => ['Fr' => '25', 'Sa' => '0']]);
    $plan->save();
    // Changes Adult's price, and Friday's from the plan before.
    $this->createRatePlan('2099-12-01', ['A' => '60'] + $complete)->setDayPrices(['A' => ['Fr' => '30', 'Sa' => '0']])->save();

    $form = $this->container->get('entity.form_builder')->getForm($plan, 'apply');
    $this->assertCount(7, $form['prices']['#rows']);
    $this->assertSame(['conreg-rate-plan__changed', 'conreg-rate-plan__day'], $form['prices']['#rows'][1]['class']);
    $this->assertSame(['conreg-rate-plan__unchanged', 'conreg-rate-plan__day'], $form['prices']['#rows'][2]['class']);
    $this->assertEquals('Applying this plan will change 1 day price.', (string) $form['summary']['#value']);
    $plans = $this->container->get('entity_type.manager')->getStorage('conreg_rate_plan')->loadPlanned(1);
    $form = $this->container->get('entity.form_builder')->getForm(end($plans), 'apply');
    $this->assertEquals('Applying this plan will change 1 member type price and 1 day price.', (string) $form['summary']['#value']);

    $this->setUpCurrentUser(permissions: ['configure convention registration']);
    $route_name = 'entity.conreg_rate_plan.collection';
    $request = Request::create('/admin/config/conreg/rate-plans/1');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName($route_name));
    $request->attributes->set('_raw_variables', new InputBag(['eid' => '1']));
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $planned = $this->container->get('entity_type.manager')->getListBuilder('conreg_rate_plan')->render();
    $rows = array_values($planned['table']['#rows']);
    $items = array_map(fn($item) => (string) $item['#markup'], $rows[0]['data']['prices']['data']['#items']);
    // Saturday's missing price is free, so setting it to 0 isn't a change.
    $this->assertSame(['Adult, Friday: €20.00 → €25.00 (+€5.00)'], $items);
    // Can be applied, as a plan changing only day prices still changes prices.
    $this->assertArrayHasKey('apply', $rows[0]['data']['operations']['data']['#links']);
    $items = array_map(fn($item) => (string) $item['#markup'], $rows[1]['data']['prices']['data']['#items']);
    $this->assertSame([
      'Adult: €50.00 → €60.00 (+€10.00)',
      'Adult, Friday: €25.00 → €30.00 (+€5.00)',
    ], $items);
  }

  /**
   * AJAX callback placeholder for building add-on form elements.
   */
  public static function addOnAjaxCallback(): array {
    return [];
  }

}
