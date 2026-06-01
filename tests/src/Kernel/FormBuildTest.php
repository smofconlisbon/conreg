<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Addons;
use Drupal\conreg\Form\Admin\EventAddOns;
use Drupal\conreg\Plugin\Derivative\EventsMenuDeriver;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Routing\Route;

/**
 * Tests that forms load correctly.
 *
 * @group conreg
 */
class FormBuildTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
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
   * Test validating active add-ons with only a free amount label.
   */
  public function testActiveFreeAmountAddOnDoesNotRequireGeneralLabel(): void {
    $form = [];
    $form_state = (new FormState())->setValues([
      'new_addon' => [
        'addon_name' => '',
      ],
      'addons' => [
        'donation' => [
          'addon' => [
            'active' => 1,
            'label' => '',
          ],
          'free' => [
            'label' => 'Donation amount',
          ],
        ],
      ],
    ]);

    $this->container
      ->get('class_resolver')
      ->getInstanceFromDefinition(EventAddOns::class)
      ->validateForm($form, $form_state);

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * AJAX callback placeholder for building add-on form elements.
   */
  public static function addOnAjaxCallback(): array {
    return [];
  }

}
