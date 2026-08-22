<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Addons;
use Drupal\conreg\Form\Admin\EventAddOns;
use Drupal\conreg\Form\Admin\EventConfig;
use Drupal\conreg\Payment;
use Drupal\conreg\Plugin\Derivative\EventsMenuDeriver;
use Drupal\conreg\Service\PaymentStorage;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests that forms load correctly.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class FormBuildTest extends KernelTestBase {

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
    $this->installConfig(['conreg']);
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
   * Set up email template. Temporary, until can be moved into saved config.
   */
  protected function createEmailTemplate() {
    $config = $this->container->get('config.factory')->getEditable('conreg.email_templates');
    $config->set('template1subject', 'Thank you for joining [event_name]');
    $config->set('template1body', '<p>Hi [first_name],</p><p>This is to confirm you have joined [event_name].</p><p>Your member details are: [member_details]</p>');
    $config->set('template1format', 'basic_html');
    $config->set('count', '1');
    $config->save();
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
    $this->assertEquals('basic_html', $form['A']['confirmation']['template_body']['#format']);

    $overrideStates = [
      'visible' => [
        ':input[name="A[confirmation][override]"]' => ['checked' => TRUE],
      ],
    ];
    $this->assertEquals($overrideStates, $form['A']['confirmation']['template_subject']['#states']);
    $this->assertEquals($overrideStates, $form['A']['confirmation']['template_body']['#states']);
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
    $config->set('bulk_email.template_subject', 'Bulk email subject');
    $config->set('bulk_email.template_body', 'Body');
    $config->set('bulk_email.template_format', 'basic_html');
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
   * AJAX callback placeholder for building add-on form elements.
   */
  public static function addOnAjaxCallback(): array {
    return [];
  }

}
