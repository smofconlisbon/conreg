<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

/**
 * Typed replacement for the 5-element array returned by getAllMemberPrices().
 */
final readonly class PricingResult {

  /**
   * Constructs a PricingResult.
   *
   * @param \Drupal\conreg\Pricing\MemberPriceResult[] $memberResults
   *   Each member's priced result, keyed by member number.
   * @param \Drupal\conreg\Pricing\PriceLine[] $globalLines
   *   Sitewide (non-per-member) price lines, e.g. global add-ons.
   */
  public function __construct(
    public array $memberResults,
    public array $globalLines = [],
  ) {}

  /**
   * The full price before any whole-registration adjustment.
   */
  public function fullPrice(): float {
    $total = 0.0;
    foreach ($this->globalLines as $line) {
      $total += $line->amount;
    }
    foreach ($this->memberResults as $result) {
      $total += $result->basePrice() + $result->addOnPrice();
    }
    return $total;
  }

  /**
   * The total amount deducted by whole-registration adjustments.
   */
  public function discountAmount(): float {
    $total = 0.0;
    foreach ($this->memberResults as $result) {
      $total += $result->adjustmentAmount();
    }
    return -$total;
  }

  /**
   * The total price after adjustments.
   */
  public function totalPrice(): float {
    return $this->fullPrice() - $this->discountAmount();
  }

  /**
   * The total price after adjustments, excluding free-form contributions.
   */
  public function totalPriceMinusFree(): float {
    $total = 0.0;
    foreach ($this->globalLines as $line) {
      if (!$line->excludeFromMinusFree) {
        $total += $line->amount;
      }
    }
    foreach ($this->memberResults as $result) {
      $total += $result->priceMinusFree();
    }
    return $total;
  }

}
