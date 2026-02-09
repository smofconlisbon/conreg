<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\Request;
use Drupal\conreg\Controller\LoginController;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests LoginController, making sure member can log in to member portal.
 *
 * @group conreg
 */
class LoginControllerKernelTest extends KernelTestBase {

  protected const CURRENT_TIME = 1700000000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'conreg',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install tables required by ConReg.
    $this->installSchema('conreg', ['conreg_members', 'conreg_events']);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();

    // Set up user schema and defaults.
    $this->installEntitySchema('user');

    // Create mocked Time service.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')
    // Fixed timestamp for deterministic tests.
      ->willReturn(self::CURRENT_TIME);

    $this->container->set('datetime.time', $time);

    // Create mocked LanguageManager service.
    $language = new Language(['id' => 'en']);

    $langManager = $this->createMock(LanguageManagerInterface::class);
    $langManager->method('getCurrentLanguage')
      ->willReturn($language);
    $langManager->method('getDefaultLanguage')
      ->willReturn($language);

    $this->container->set('language_manager', $langManager);
  }

  /**
   * Helper: Create a test member.
   */
  protected function createTestMember(array $overrides = []): int {
    $defaults = [
      'mid' => 1,
      'eid' => 1,
      'language' => 'en',
      'first_name' => 'Test',
      'last_name' => 'User',
      'email' => 'test@example.com',
      'random_key' => 12345,
      'login_exp_date' => self::CURRENT_TIME + 1800,
      'join_date' => self::CURRENT_TIME - 7200,
      'update_date' => self::CURRENT_TIME - 3600,
    ];

    $fields = $overrides + $defaults;

    return Database::getConnection()
      ->insert('conreg_members')
      ->fields($fields)
      ->execute();
  }

  /**
   * Call the controller the same way the route would.
   */
  protected function callController($mid, $key, $expiry) {

    $request = Request::create("/members/login/$mid/$key/$expiry");
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $controller = LoginController::create($this->container);
    return $controller->memberLoginAndRedirect($mid, $key, $expiry, $request);
  }

  /**
   * Test that a member with an existing user is logged in to member portal.
   */
  public function testExistingUserLoginRedirectsToPortal(): void {
    $mid = $this->createTestMember();

    // Create user in advance.
    $user = User::create([
      'name' => 'test@example.com',
      'mail' => 'test@example.com',
      'status' => 1,
    ]);
    $user->save();

    $key = 12345;
    $expiry = self::CURRENT_TIME + 1800;

    $result = $this->callController($mid, $key, $expiry);

    // Make sure we haven't got back a render array.
    $this->assertIsNotArray($result);
    // Check that redirect URL contains the member portal path.
    $this->assertStringContainsString('/members/portal', $result->getTargetUrl());

    $current_user = $this->container->get('current_user');
    $this->assertTrue($current_user->isAuthenticated());
  }

  /**
   * Test that a member with no user is created and logged in to portal.
   */
  public function testNewUserLoginRedirectsToPortal(): void {
    $mid = $this->createTestMember();

    // These values must match whatever logic your controller expects
    // for generating a valid key.
    $key = 12345;
    $expiry = self::CURRENT_TIME + 1800;

    $result = $this->callController($mid, $key, $expiry);

    // Make sure we haven't got back a render array.
    $this->assertIsNotArray($result);
    // Check that redirect URL contains the member portal path.
    $this->assertStringContainsString('/members/portal', $result->getTargetUrl());

    $current_user = $this->container->get('current_user');
    $this->assertTrue($current_user->isAuthenticated());
  }

  /**
   * Test that if login has expired, suitable message is displayed.
   */
  public function testExpiredLoginDenied(): void {
    $mid = $this->createTestMember(['login_exp_date' => self::CURRENT_TIME - 1800]);

    $key = 12345;
    // Already expired.
    $expiry = self::CURRENT_TIME - 1800;

    $result = $this->callController($mid, $key, $expiry);

    // Check result is render array.
    $this->assertIsArray($result);

    $this->assertStringContainsString(
      'Login has expired. Please use Member Check to generate a new login link',
      $result['markup']['#markup'],
    );

    // Make sure user not logged in.
    $current_user = $this->container->get('current_user');
    $this->assertFalse($current_user->isAuthenticated());
  }

  /**
   * Test that if credentials are not valid, suitable message displayed.
   */
  public function testInvalidCredentials(): void {
    $mid = $this->createTestMember();

    $bad_key = 999999;
    $expiry = self::CURRENT_TIME;

    $result = $this->callController($mid, $bad_key, $expiry);

    // Check result is render array.
    $this->assertIsArray($result);

    $this->assertStringContainsString(
      'Invalid credentials',
      $result['markup']['#markup'],
    );

    $current_user = $this->container->get('current_user');
    $this->assertFalse($current_user->isAuthenticated());
  }

}
