<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\Core\Database\Database;
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
   * Test building the Registration form.
   */
  public function testMemberRegisterFormBuild() {
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
    $this->createTestMember();

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

}
