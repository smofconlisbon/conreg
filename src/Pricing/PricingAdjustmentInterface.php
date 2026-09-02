<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

/**
 * Interface for pricing_adjustment plugins.
 *
 * A pricing adjustment operates on the whole registration at once - it sees
 * every member's already-priced result and can adjust any of them (e.g.
 * the "every Nth member free" discount needs to sort every member by price
 * to decide which ones become free). A rule that only needs to price one
 * member in isolation should implement
 * \Drupal\conreg\Pricing\MemberPricingRuleInterface instead.
 */
interface PricingAdjustmentInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Computes adjustments across the whole registration.
   *
   * @param \Drupal\conreg\Pricing\MemberPriceResult[] $memberResults
   *   Every member's price, as computed so far (including any adjustment
   *   already applied by an earlier-weighted plugin).
   * @param \Drupal\conreg\Pricing\PricingContext $context
   *   The shared registration context.
   *
   * @return \Drupal\conreg\Pricing\PriceAdjustment[]
   *   The adjustments to apply.
   */
  public function adjust(array $memberResults, PricingContext $context): array;

}
