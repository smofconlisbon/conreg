<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for member_pricing_rule plugins.
 *
 * Shipped rules need no injected services, so this deliberately doesn't
 * implement ContainerFactoryPluginInterface - a rule that does need DI can
 * add it itself, following \Drupal\conreg_mailing_list's plugin base as a
 * precedent for that shape.
 */
abstract class MemberPricingRulePluginBase extends PluginBase implements MemberPricingRuleInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

}
