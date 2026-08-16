<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit;

use Drupal\conreg\Member;
use Drupal\conreg\MemberOption;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Member::hasOption() against real-world DB-shaped data.
 */
#[CoversClass(Member::class)]
#[Group('conreg')]
class MemberHasOptionTest extends UnitTestCase {

  /**
   * Builds a member with the given member options.
   */
  protected function createMember(array $options): Member {
    $member = Member::newMember([]);
    $member->options = $options;
    return $member;
  }

  /**
   * Option IDs loaded from the database arrive as strings, not ints.
   *
   * The hasOption() method takes an int, so this must still match.
   */
  public function testHasOptionMatchesWhenStoredOptionIdIsString(): void {
    $member = $this->createMember([new MemberOption(1, '5', 1, '')]);

    $this->assertTrue($member->hasOption(5));
  }

  /**
   * An option row that exists but isn't selected doesn't count as held.
   */
  public function testHasOptionFalseWhenOptionNotSelected(): void {
    $member = $this->createMember([new MemberOption(1, 5, 0, '')]);

    $this->assertFalse($member->hasOption(5));
  }

  /**
   * A different option ID doesn't match.
   */
  public function testHasOptionFalseWhenNoMatchingOption(): void {
    $member = $this->createMember([new MemberOption(1, 6, 1, '')]);

    $this->assertFalse($member->hasOption(5));
  }

  /**
   * A member with no options at all doesn't have any option.
   */
  public function testHasOptionFalseWhenNoOptions(): void {
    $member = $this->createMember([]);

    $this->assertFalse($member->hasOption(5));
  }

}
