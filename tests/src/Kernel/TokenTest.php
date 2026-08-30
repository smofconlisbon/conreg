<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that conreg tokens are discovered and invoked via the real container.
 *
 * Token-value logic itself is covered by the Unit test in
 * Drupal\Tests\conreg\Unit\TokenTest; this test exists only to prove
 * ConregTokenHooks is actually wired up through Drupal's hook discovery and
 * service registration, which a Unit test can't fake.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class TokenTest extends KernelTestBase {

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
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * The conreg:event-name token round-trips through \Drupal::token().
   */
  public function testEventNameTokenIsDiscoveredAndReplaced(): void {
    $data = [
      'event' => [
        'name' => 'An amazing event',
        'email' => 'contact@example.com',
      ],
    ];

    $result = \Drupal::token()->replace('Thank you for joining [conreg:event-name]', $data);

    $this->assertSame('Thank you for joining An amazing event', $result);
  }

}
