<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The member_pricing_rule attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MemberPricingRule extends AttributeBase {

  /**
   * Constructs a new MemberPricingRule instance.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the plugin.
   * @param int $weight
   *   (optional) Rules run in ascending weight order.
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
