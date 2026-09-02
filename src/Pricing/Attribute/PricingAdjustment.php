<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The pricing_adjustment attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PricingAdjustment extends AttributeBase {

  /**
   * Constructs a new PricingAdjustment instance.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the plugin.
   * @param int $weight
   *   (optional) Adjustments run in ascending weight order, each seeing
   *   the effect of any earlier adjustment.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
