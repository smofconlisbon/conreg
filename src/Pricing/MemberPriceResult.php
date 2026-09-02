<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

/**
 * Typed replacement for the stdClass returned by the old getMemberPrice().
 */
final readonly class MemberPriceResult {

  /**
   * Constructs a MemberPriceResult.
   *
   * @param int $memberNo
   *   The member's position on the registration/payment.
   * @param int|null $mid
   *   The persisted member ID, or NULL if not yet saved.
   * @param string $memberType
   *   The member type code.
   * @param string $days
   *   Pipe-delimited selected day codes (empty for the type's default).
   * @param string $daysDesc
   *   Human-readable, comma-separated day names.
   * @param \Drupal\conreg\Pricing\PriceLine[] $lines
   *   The priced components making up this member's price.
   * @param \Drupal\conreg\Pricing\PriceAdjustment[] $adjustments
   *   Whole-registration adjustments targeting this member.
   */
  public function __construct(
    public int $memberNo,
    public ?int $mid,
    public string $memberType,
    public string $days,
    public string $daysDesc,
    public array $lines = [],
    public array $adjustments = [],
  ) {}

  /**
   * Returns a copy of this result with additional price lines.
   *
   * @param \Drupal\conreg\Pricing\PriceLine[] $lines
   *   The lines to append.
   */
  public function withLines(array $lines): self {
    return new self(
      $this->memberNo,
      $this->mid,
      $this->memberType,
      $this->days,
      $this->daysDesc,
      [...$this->lines, ...$lines],
      $this->adjustments,
    );
  }

  /**
   * Returns a copy of this result with additional adjustments.
   *
   * @param \Drupal\conreg\Pricing\PriceAdjustment[] $adjustments
   *   The adjustments to append.
   */
  public function withAdjustments(array $adjustments): self {
    return new self(
      $this->memberNo,
      $this->mid,
      $this->memberType,
      $this->days,
      $this->daysDesc,
      $this->lines,
      [...$this->adjustments, ...$adjustments],
    );
  }

  /**
   * The raw base (type + day) price, never reduced by an adjustment.
   */
  public function basePrice(): float {
    return $this->sumLines('base', includeExcluded: TRUE);
  }

  /**
   * The raw add-on total, including free-form ("pay what you want") amounts.
   */
  public function addOnPrice(): float {
    return $this->sumLines('addon', includeExcluded: TRUE);
  }

  /**
   * The add-on total excluding free-form contributions.
   */
  public function addOnPriceMinusFree(): float {
    return $this->sumLines('addon', includeExcluded: FALSE);
  }

  /**
   * The sum of all whole-registration adjustments targeting this member.
   */
  public function adjustmentAmount(): float {
    $total = 0.0;
    foreach ($this->adjustments as $adjustment) {
      $total += $adjustment->amount;
    }
    return $total;
  }

  /**
   * The member's total price: base + add-ons + adjustments.
   */
  public function price(): float {
    return $this->basePrice() + $this->addOnPrice() + $this->adjustmentAmount();
  }

  /**
   * The member's total price, excluding free-form add-on contributions.
   */
  public function priceMinusFree(): float {
    return $this->basePrice() + $this->addOnPriceMinusFree() + $this->adjustmentAmount();
  }

  /**
   * The base price alone, after adjustments but excluding add-ons.
   *
   * This is the amount used for the per-member 'member' payment line -
   * add-ons are persisted as their own separate payment lines.
   */
  public function basePriceMinusFree(): float {
    return $this->basePrice() + $this->adjustmentAmount();
  }

  /**
   * Sums this member's price lines for one category.
   */
  private function sumLines(string $category, bool $includeExcluded): float {
    $total = 0.0;
    foreach ($this->lines as $line) {
      if ($line->category === $category && ($includeExcluded || !$line->excludeFromMinusFree)) {
        $total += $line->amount;
      }
    }
    return $total;
  }

}
