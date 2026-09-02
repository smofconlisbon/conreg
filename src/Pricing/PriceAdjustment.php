<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

/**
 * A whole-registration adjustment targeting one member's price.
 */
final readonly class PriceAdjustment {

  /**
   * Constructs a PriceAdjustment.
   *
   * Adjustments always apply to a member's derived totals
   * (`price()`/`priceMinusFree()`/`basePriceMinusFree()`) - never to the
   * raw per-category breakdown (`basePrice()`/`addOnPrice()`), which always
   * reflects what was actually charged for that category before any
   * whole-registration adjustment. This mirrors the original nth-member-free
   * discount, which reduces the member's total but leaves the persisted
   * `member_price` (base price) column showing the full, pre-discount type
   * price.
   *
   * @param int $targetMemberNo
   *   The member number this adjustment applies to.
   * @param float $amount
   *   The adjustment amount. Negative for a discount, positive for a
   *   surcharge.
   * @param string $pluginId
   *   The plugin ID that produced this adjustment.
   * @param string $reason
   *   A machine-readable reason, e.g. 'nth_member_free'.
   */
  public function __construct(
    public int $targetMemberNo,
    public float $amount,
    public string $pluginId,
    public string $reason,
  ) {}

}
