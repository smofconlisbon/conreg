<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_planz\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that conreg-planz tokens are discovered via the real container.
 *
 * A thin wiring check, matching Drupal\Tests\conreg\Kernel\TokenTest - it
 * exists only to prove ConregPlanzHooks's token methods are actually
 * wired up through Drupal's hook discovery and service registration.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregPlanzTokenTest extends KernelTestBase {

  /**
   * Modules to enable for this test.
   *
   * @var array
   */
  protected static $modules = ['system', 'user', 'datetime', 'key', 'token', 'conreg', 'conreg_planz'];

  /**
   * The conreg-planz:user/password/url tokens round-trip via the token service.
   */
  public function testPlanzTokensAreDiscoveredAndReplaced(): void {
    $data = [
      'planz' => [
        'user' => 'A0007',
        'password' => 'sw0rdf1sh',
        'url' => 'https://planz.example.com',
      ],
    ];

    $result = \Drupal::token()->replace('User [conreg-planz:user], password [conreg-planz:password], site [conreg-planz:url]', $data);

    $this->assertSame('User A0007, password sw0rdf1sh, site https://planz.example.com', $result);
  }

}
