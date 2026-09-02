<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

/**
 * One priced component contributed by a pricing plugin.
 */
final readonly class PriceLine {

  /**
   * Constructs a PriceLine.
   *
   * @param string $id
   *   Machine identifier for this line (e.g. 'base_type', 'addon:banquet').
   * @param string $category
   *   Which bucket this line belongs to: 'base' or 'addon'. A plain string
   *   (not an enum) so submodules can introduce new categories without
   *   changing this class.
   * @param float $amount
   *   The amount contributed by this line.
   * @param string $label
   *   A plain-text label for this line (no markup/translation - the form
   *   layer is responsible for building any display markup).
   * @param bool $excludeFromMinusFree
   *   TRUE if this line is a free-form ("pay what you want") contribution
   *   that should be excluded from `MemberPriceResult::priceMinusFree()`.
   */
  public function __construct(
    public string $id,
    public string $category,
    public float $amount,
    public string $label,
    public bool $excludeFromMinusFree = FALSE,
  ) {}

}
