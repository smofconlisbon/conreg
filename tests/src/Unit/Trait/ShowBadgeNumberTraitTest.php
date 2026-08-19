<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit\Trait;

use Drupal\conreg\Trait\ShowBadgeNumberTrait;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ShowBadgeNumberTrait::showBadgeNumber().
 */
#[CoversClass(ShowBadgeNumberTrait::class)]
#[Group('conreg')]
class ShowBadgeNumberTraitTest extends UnitTestCase {

  /**
   * The trait under test, exposed via a concrete test double.
   */
  protected TestShowBadgeNumber $subject;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->subject = new TestShowBadgeNumber();
  }

  /**
   * Builds a mocked config returning the given digit width.
   */
  protected function configWithDigits(int $digits): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('member_no_digits')
      ->willReturn($digits);
    return $config;
  }

  /**
   * The badge type is prefixed to the zero-padded member number.
   */
  public function testFormatsBadgeTypeAndZeroPaddedNumber(): void {
    $result = $this->subject->showBadgeNumberForTest(
      ['member_no' => 7, 'badge_type' => 'A'],
      $this->configWithDigits(4),
    );

    $this->assertSame('A0007', $result);
  }

  /**
   * The badge type is trimmed before being used as a prefix.
   */
  public function testTrimsBadgeType(): void {
    $result = $this->subject->showBadgeNumberForTest(
      ['member_no' => 3, 'badge_type' => ' A '],
      $this->configWithDigits(2),
    );

    $this->assertSame('A03', $result);
  }

  /**
   * A falsy member number (e.g. not yet assigned) returns an empty string.
   */
  public function testFalsyMemberNumberReturnsEmptyString(): void {
    $result = $this->subject->showBadgeNumberForTest(
      ['member_no' => 0, 'badge_type' => 'A'],
      $this->configWithDigits(4),
    );

    $this->assertSame('', $result);
  }

  /**
   * Each call re-reads the digit width from its own config.
   *
   * Regression guard: an earlier version cached the digit width in a
   * function-local `static` variable seeded from whichever config was
   * passed on the first call in a PHP-FPM worker's lifetime, so every
   * other event silently reused that first event's digit width instead of
   * its own. Calling the method for two events with different widths in
   * the same process - exactly what happens across requests handled by
   * the same worker - must produce independently correct results.
   */
  public function testDigitWidthIsNotCachedAcrossCallsWithDifferentConfig(): void {
    $first = $this->subject->showBadgeNumberForTest(
      ['member_no' => 7, 'badge_type' => 'A'],
      $this->configWithDigits(4),
    );
    $second = $this->subject->showBadgeNumberForTest(
      ['member_no' => 7, 'badge_type' => 'B'],
      $this->configWithDigits(2),
    );

    $this->assertSame('A0007', $first);
    $this->assertSame('B07', $second);
  }

}

/**
 * Exposes ShowBadgeNumberTrait::showBadgeNumber() for unit testing.
 */
class TestShowBadgeNumber {
  use ShowBadgeNumberTrait;

  /**
   * Calls the protected showBadgeNumber() method.
   */
  public function showBadgeNumberForTest(array $member, ImmutableConfig $config): string {
    return $this->showBadgeNumber($member, $config);
  }

}
