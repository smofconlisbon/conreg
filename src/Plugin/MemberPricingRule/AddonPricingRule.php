<?php

declare(strict_types=1);

namespace Drupal\conreg\Plugin\MemberPricingRule;

use Drupal\conreg\Addons;
use Drupal\conreg\Pricing\Attribute\MemberPricingRule;
use Drupal\conreg\Pricing\MemberPriceContribution;
use Drupal\conreg\Pricing\MemberPricingRulePluginBase;
use Drupal\conreg\Pricing\PriceLine;
use Drupal\conreg\Pricing\PricingContext;
use Drupal\conreg\Pricing\PricingSubject;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Prices a member's own (non-global) add-on selections.
 *
 * Ported from the per-member branch of the original
 * Addons::getAllAddonPrices(). Global (sitewide) add-ons are handled by
 * \Drupal\conreg\Plugin\PricingAdjustment\GlobalAddOnPricingAdjustment
 * instead, since they aren't owned by any one member.
 */
#[MemberPricingRule(
  id: 'addon',
  label: new TranslatableMarkup('Add-on pricing'),
  weight: 10,
)]
final class AddonPricingRule extends MemberPricingRulePluginBase {

  /**
   * {@inheritdoc}
   */
  public function priceMember(PricingSubject $subject, PricingContext $context): MemberPriceContribution {
    $lines = [];
    $addOns = $context->config->get('add-ons') ?? [];

    foreach ($subject->addOns as $addOnId => $selection) {
      $addOnVals = $addOns[$addOnId] ?? [];
      $addon = $addOnVals['addon'] ?? [];
      if (($addon['active'] ?? 0) != 1 || !empty($addon['global'])) {
        // Add-on no longer active, or has become global since this
        // selection was made - skip it rather than mis-price it.
        continue;
      }
      $isFree = !empty($addon['free']);
      $label = Addons::getAddOnLabel($addOnId, $addOnVals);

      if ($isFree) {
        if ($selection->freeAmount > 0) {
          $lines[] = new PriceLine('addon:' . $addOnId, 'addon', $selection->freeAmount, $label, excludeFromMinusFree: TRUE);
        }
      }
      elseif ($selection->option !== NULL) {
        [, $addOnPrices] = Addons::memberAddons($addon['options'] ?? '');
        $price = (float) ($addOnPrices[$selection->option] ?? 0);
        $lines[] = new PriceLine('addon:' . $addOnId, 'addon', $price, $label);
      }
    }

    return new MemberPriceContribution($lines);
  }

}
