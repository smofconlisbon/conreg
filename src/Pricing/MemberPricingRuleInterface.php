<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

/**
 * Interface for member_pricing_rule plugins.
 *
 * A member pricing rule prices one member in isolation - it never needs to
 * see any other member on the registration. Rules that need the whole
 * registration (e.g. a discount that depends on every member's price)
 * should implement \Drupal\conreg\Pricing\PricingAdjustmentInterface
 * instead.
 */
interface MemberPricingRuleInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Prices one member.
   *
   * @param \Drupal\conreg\Pricing\PricingSubject $subject
   *   The member to price.
   * @param \Drupal\conreg\Pricing\PricingContext $context
   *   The shared registration context.
   *
   * @return \Drupal\conreg\Pricing\MemberPriceContribution
   *   The price line(s) this rule contributes, plus day metadata if this
   *   rule resolves day selection.
   */
  public function priceMember(PricingSubject $subject, PricingContext $context): MemberPriceContribution;

}
