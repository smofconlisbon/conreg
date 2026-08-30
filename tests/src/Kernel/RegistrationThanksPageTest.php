<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Controller\ConregController;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the standalone thank-you page resolves conreg tokens.
 *
 * ConregController::registrationThanks() used to render
 * thanks.thank_you_message as raw #markup with no token processing at all,
 * unlike Checkout::showThankYouPage(), which goes through the real Token
 * API. This route has no payment or member context, so it should still
 * resolve event-scoped tokens.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class RegistrationThanksPageTest extends KernelTestBase {

  /**
   * Modules to enable for this test.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'key',
    'conreg',
    'user',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * Set up database tables and config for the controller.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('conreg', ['conreg_events', 'conreg_members']);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();
  }

  /**
   * Event-scoped tokens resolve in the rendered page, with no member data.
   */
  public function testRegistrationThanksResolvesEventTokens(): void {
    $this->container->get('config.factory')->getEditable('conreg.settings.1')
      ->set('thanks.thank_you_message', 'Thanks for joining [conreg:event-name]!')
      ->save();

    $controller = ConregController::create($this->container);
    $content = $controller->registrationThanks(1);

    $this->assertSame('Thanks for joining Test event!', $content['message']['#text']);
  }

}
