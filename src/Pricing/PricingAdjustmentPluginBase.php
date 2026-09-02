<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for pricing_adjustment plugins.
 *
 * Shipped adjustments need no injected services, so this deliberately
 * doesn't implement ContainerFactoryPluginInterface - an adjustment that
 * does need DI can add it itself, following
 * \Drupal\conreg_mailing_list's plugin base as a precedent for that shape.
 */
abstract class PricingAdjustmentPluginBase extends PluginBase implements PricingAdjustmentInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

}
