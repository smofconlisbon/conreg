<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_discord\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that conreg-discord tokens are discovered via the real container.
 *
 * A thin wiring check, matching Drupal\Tests\conreg\Kernel\TokenTest - it
 * exists only to prove ConregDiscordTokenHooks is actually wired up
 * through Drupal's hook discovery and service registration.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregDiscordTokenTest extends KernelTestBase {

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
    'token',
    'conreg',
    'conreg_discord',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * The conreg-discord:invite-url token round-trips through \Drupal::token().
   */
  public function testInviteUrlTokenIsDiscoveredAndReplaced(): void {
    $data = ['discord' => ['invite_url' => 'https://discord.gg/example']];

    $result = \Drupal::token()->replace('Join us: [conreg-discord:invite-url]', $data);

    $this->assertSame('Join us: https://discord.gg/example', $result);
  }

}
