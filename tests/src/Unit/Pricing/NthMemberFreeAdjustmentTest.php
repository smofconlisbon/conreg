<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit\Pricing;

use Drupal\conreg\Pricing\MemberPriceResult;
use Drupal\conreg\Pricing\PriceLine;
use Drupal\conreg\Pricing\PricingContext;
use Drupal\conreg\Plugin\PricingAdjustment\NthMemberFreeAdjustment;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests NthMemberFreeAdjustment.
 *
 * Ported from the original getAllMemberPrices() sort/modulo/tie-break
 * logic.
 */
#[CoversClass(NthMemberFreeAdjustment::class)]
#[Group('conreg')]
class NthMemberFreeAdjustmentTest extends UnitTestCase {

  /**
   * Builds a priced member with a single base price line.
   */
  protected function makeResult(int $memberNo, float $basePrice, float $addOnPrice = 0.0): MemberPriceResult {
    $lines = [new PriceLine('base_type', 'base', $basePrice, 'Base')];
    if ($addOnPrice > 0) {
      $lines[] = new PriceLine('addon:x', 'addon', $addOnPrice, 'Add-on');
    }
    return new MemberPriceResult($memberNo, NULL, 'A', '', '', $lines);
  }

  /**
   * Builds a context with the given discount settings.
   */
  protected function makeContext(bool $discountEnabled, int $discountFreeEvery): PricingContext {
    return new PricingContext(1, $this->createMock(ImmutableConfig::class), [], '$', $discountEnabled, $discountFreeEvery);
  }

  /**
   * When the discount is disabled, no adjustments are produced.
   */
  public function testNoAdjustmentsWhenDiscountDisabled(): void {
    $results = [
      1 => $this->makeResult(1, 50.0),
      2 => $this->makeResult(2, 50.0),
    ];
    $rule = new NthMemberFreeAdjustment([], 'nth_member_free', ['label' => 'Nth free']);

    $this->assertSame([], $rule->adjust($results, $this->makeContext(FALSE, 1)));
  }

  /**
   * Equal-priced members are made free in member-number order.
   */
  public function testTiesBrokenByMemberNumberAscending(): void {
    $results = [
      3 => $this->makeResult(3, 50.0),
      1 => $this->makeResult(1, 50.0),
      2 => $this->makeResult(2, 50.0),
    ];
    $rule = new NthMemberFreeAdjustment([], 'nth_member_free', ['label' => 'Nth free']);

    // Every 2nd member (discountFreeEvery=1) free, sorted [1,2,3] since all
    // tie on price - member 2 is the 2nd in that order.
    $adjustments = $rule->adjust($results, $this->makeContext(TRUE, 1));

    $this->assertCount(1, $adjustments);
    $this->assertSame(2, $adjustments[0]->targetMemberNo);
    $this->assertSame(-50.0, $adjustments[0]->amount);
  }

  /**
   * The most expensive members are made free first.
   */
  public function testMostExpensiveMembersFreeFirst(): void {
    $results = [
      1 => $this->makeResult(1, 30.0),
      2 => $this->makeResult(2, 90.0),
      3 => $this->makeResult(3, 60.0),
    ];
    $rule = new NthMemberFreeAdjustment([], 'nth_member_free', ['label' => 'Nth free']);

    // Sorted desc by price: [2 (90), 3 (60), 1 (30)]. Every 3rd (free_every=2)
    // free -> position 3 -> member 1, even though it's the cheapest.
    $adjustments = $rule->adjust($results, $this->makeContext(TRUE, 2));

    $this->assertCount(1, $adjustments);
    $this->assertSame(1, $adjustments[0]->targetMemberNo);
    $this->assertSame(-30.0, $adjustments[0]->amount);
  }

  /**
   * A discountFreeEvery of 0 makes every eligible member free.
   */
  public function testFreeEveryZeroMakesAllEligibleMembersFree(): void {
    $results = [
      1 => $this->makeResult(1, 30.0),
      2 => $this->makeResult(2, 40.0),
    ];
    $rule = new NthMemberFreeAdjustment([], 'nth_member_free', ['label' => 'Nth free']);

    $adjustments = $rule->adjust($results, $this->makeContext(TRUE, 0));

    $this->assertCount(2, $adjustments);
  }

  /**
   * A member with a zero base price (already free) is never selected.
   *
   * They also don't consume a slot in the count.
   */
  public function testZeroBasePriceMemberIsNotEligible(): void {
    $results = [
      1 => $this->makeResult(1, 0.0),
      2 => $this->makeResult(2, 50.0),
      3 => $this->makeResult(3, 50.0),
    ];
    $rule = new NthMemberFreeAdjustment([], 'nth_member_free', ['label' => 'Nth free']);

    // Only members 2 and 3 are eligible; every 2nd (free_every=1) free.
    $adjustments = $rule->adjust($results, $this->makeContext(TRUE, 1));

    $this->assertCount(1, $adjustments);
    $this->assertSame(3, $adjustments[0]->targetMemberNo);
  }

  /**
   * The discount reduces the base price only - add-ons are preserved.
   */
  public function testAddOnPriceIsPreservedWhenMemberBecomesFree(): void {
    $result = $this->makeResult(1, 50.0, addOnPrice: 15.0);
    $rule = new NthMemberFreeAdjustment([], 'nth_member_free', ['label' => 'Nth free']);

    $adjustments = $rule->adjust([1 => $result], $this->makeContext(TRUE, 0));
    $adjusted = $result->withAdjustments($adjustments);

    $this->assertSame(50.0, $adjusted->basePrice());
    $this->assertSame(15.0, $adjusted->addOnPrice());
    $this->assertSame(15.0, $adjusted->price());
  }

}
