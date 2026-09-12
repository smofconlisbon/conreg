<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Member;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Regression coverage for #3596624: Member::loadMember() and a missing mid.
 *
 * Member::loadMember() is declared `: Member` - promising an object every
 * time - but internally it calls Member::newMember(), which already
 * returns `Member|NULL` when the requested row doesn't exist, and then
 * immediately does `$member->options = ...` before returning. For a $mid
 * that isn't in the database, that dereferences NULL and throws a fatal
 * error - "Attempt to assign property "options" on null" - instead of
 * honestly reporting that no such member exists.
 */
#[CoversClass(Member::class)]
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MemberLoadMemberTest extends KernelTestBase {

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
   * Set up database tables and a single member fixture.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'datetime']);
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

    Database::getConnection()->insert('conreg_members')
      ->fields([
        'mid' => 1,
        'eid' => 1,
        'lead_mid' => 1,
        'language' => 'en',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane.doe@example.com',
        'join_date' => \Drupal::time()->getCurrentTime(),
        'update_date' => \Drupal::time()->getCurrentTime(),
      ])
      ->execute();
  }

  /**
   * Non-regression: an existing member ID still loads correctly.
   */
  public function testLoadMemberReturnsPopulatedMemberForExistingMid(): void {
    $member = Member::loadMember(1);

    $this->assertSame('Jane', $member->first_name);
    $this->assertSame('Doe', $member->last_name);
    $this->assertIsArray($member->options);
  }

  /**
   * A $mid with no matching row must not throw a fatal error.
   *
   * Currently throws a fatal error - "Attempt to assign property "options"
   * on null" - before ever reaching a return statement, instead of
   * returning NULL as callers should be able to expect from a "load"
   * method.
   */
  public function testLoadMemberReturnsNullForUnknownMid(): void {
    $member = Member::loadMember(99999);

    $this->assertNull($member);
  }

}
