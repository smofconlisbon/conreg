<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit\Pricing;

use Drupal\conreg\Pricing\PricingContext;
use Drupal\conreg\Pricing\PricingSubject;
use Drupal\conreg\Plugin\MemberPricingRule\BaseTypePricingRule;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests BaseTypePricingRule, ported from the original getMemberPrice().
 */
#[CoversClass(BaseTypePricingRule::class)]
#[Group('conreg')]
class BaseTypePricingRuleTest extends UnitTestCase {

  /**
   * Builds a type definition matching ConregOptions::memberTypes() shape.
   */
  protected function makeType(float $price, string $defaultDays = '', ?array $days = NULL): object {
    $type = new \stdClass();
    $type->price = $price;
    $type->defaultDays = $defaultDays;
    if ($days !== NULL) {
      $type->days = $days;
    }
    return $type;
  }

  /**
   * Builds a day option matching ConregOptions::memberTypes() shape.
   */
  protected function makeDay(float $price, string $name): object {
    return (object) ['price' => $price, 'name' => $name];
  }

  /**
   * Builds a context with the given member types.
   */
  protected function makeContext(array $types): PricingContext {
    return new PricingContext(1, $this->createMock(ImmutableConfig::class), $types, '$', FALSE, 0);
  }

  /**
   * A type with no day map always charges the full type price.
   */
  public function testFullPriceWhenTypeHasNoDays(): void {
    $context = $this->makeContext(['A' => $this->makeType(50.0, 'Fri|Sat|Sun')]);
    $subject = new PricingSubject(1, 'A', [], []);

    $rule = new BaseTypePricingRule([], 'base_type', ['label' => 'Base']);
    $contribution = $rule->priceMember($subject, $context);

    $this->assertSame(50.0, $contribution->lines[0]->amount);
    $this->assertSame('base', $contribution->lines[0]->category);
    $this->assertSame('Fri|Sat|Sun', $contribution->days);
    $this->assertSame('', $contribution->daysDesc);
  }

  /**
   * Selecting fewer days than the full price charges the cheaper day total.
   */
  public function testPartialDaysChargedWhenCheaperThanFullPrice(): void {
    $context = $this->makeContext([
      'A' => $this->makeType(50.0, 'Fri|Sat|Sun', [
        'Fri' => $this->makeDay(20.0, 'Friday'),
        'Sat' => $this->makeDay(20.0, 'Saturday'),
        'Sun' => $this->makeDay(20.0, 'Sunday'),
      ]),
    ]);
    $subject = new PricingSubject(1, 'A', ['Fri'], []);

    $rule = new BaseTypePricingRule([], 'base_type', ['label' => 'Base']);
    $contribution = $rule->priceMember($subject, $context);

    $this->assertSame(20.0, $contribution->lines[0]->amount);
    $this->assertSame('Fri', $contribution->days);
    $this->assertSame('Friday', $contribution->daysDesc);
  }

  /**
   * Ticking the whole-weekend checkbox charges full price, no override.
   *
   * The day code equal to the type code represents "whole weekend" -
   * matching original behavior where $daysPrice equals (not is less than)
   * $price, so the override never fires.
   */
  public function testWholeWeekendChargesFullPriceWithoutOverride(): void {
    $context = $this->makeContext([
      'A' => $this->makeType(50.0, '', [
        'Fri' => $this->makeDay(20.0, 'Friday'),
      ]),
    ]);
    $subject = new PricingSubject(1, 'A', ['A'], []);

    $rule = new BaseTypePricingRule([], 'base_type', ['label' => 'Base']);
    $contribution = $rule->priceMember($subject, $context);

    $this->assertSame(50.0, $contribution->lines[0]->amount);
    $this->assertSame('', $contribution->days);
  }

  /**
   * A day price equal to the full price doesn't override it either.
   *
   * The day price must be strictly cheaper than the full price.
   */
  public function testDayPriceEqualToFullPriceDoesNotOverride(): void {
    $context = $this->makeContext([
      'A' => $this->makeType(40.0, '', [
        'Fri' => $this->makeDay(20.0, 'Friday'),
        'Sat' => $this->makeDay(20.0, 'Saturday'),
      ]),
    ]);
    $subject = new PricingSubject(1, 'A', ['Fri', 'Sat'], []);

    $rule = new BaseTypePricingRule([], 'base_type', ['label' => 'Base']);
    $contribution = $rule->priceMember($subject, $context);

    $this->assertSame(40.0, $contribution->lines[0]->amount);
    $this->assertSame('', $contribution->days);
  }

  /**
   * A negative configured price is clamped to zero.
   */
  public function testNegativePriceClampedToZero(): void {
    $context = $this->makeContext(['A' => $this->makeType(-5.0)]);
    $subject = new PricingSubject(1, 'A', [], []);

    $rule = new BaseTypePricingRule([], 'base_type', ['label' => 'Base']);
    $contribution = $rule->priceMember($subject, $context);

    $this->assertSame(0.0, $contribution->lines[0]->amount);
  }

  /**
   * An unknown member type (or none selected yet) prices at zero.
   *
   * Matches the original getMemberPrice(), which only ever looked up a
   * price inside an `if (!empty($memberType))` guard and otherwise left
   * price at 0 - e.g. on the registration form's very first render,
   * before any member has chosen a type.
   */
  public function testUnknownTypePricesAtZero(): void {
    $context = $this->makeContext(['A' => $this->makeType(50.0)]);
    $subject = new PricingSubject(1, 'Z', [], []);

    $rule = new BaseTypePricingRule([], 'base_type', ['label' => 'Base']);
    $contribution = $rule->priceMember($subject, $context);

    $this->assertSame(0.0, $contribution->lines[0]->amount);
  }

  /**
   * No type selected at all (empty string) also prices at zero.
   */
  public function testEmptyTypePricesAtZero(): void {
    $context = $this->makeContext(['A' => $this->makeType(50.0)]);
    $subject = new PricingSubject(1, '', [], []);

    $rule = new BaseTypePricingRule([], 'base_type', ['label' => 'Base']);
    $contribution = $rule->priceMember($subject, $context);

    $this->assertSame(0.0, $contribution->lines[0]->amount);
  }

}
