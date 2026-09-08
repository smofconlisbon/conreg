<?php

declare(strict_types=1);

namespace Drupal\conreg\Plugin\MemberPricingRule;

use Drupal\conreg\Pricing\Attribute\MemberPricingRule;
use Drupal\conreg\Pricing\MemberPriceContribution;
use Drupal\conreg\Pricing\MemberPricingRulePluginBase;
use Drupal\conreg\Pricing\PriceLine;
use Drupal\conreg\Pricing\PricingContext;
use Drupal\conreg\Pricing\PricingSubject;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Prices a member from their type, applying partial-weekend day pricing.
 *
 * Ported from the original Registration::getMemberPrice()'s type/day
 * logic (excluding add-ons and the nth-member-free discount, which are
 * handled by their own plugins).
 */
#[MemberPricingRule(
  id: 'base_type',
  label: new TranslatableMarkup('Member type and day pricing'),
  weight: 0,
)]
final class BaseTypePricingRule extends MemberPricingRulePluginBase {

  /**
   * {@inheritdoc}
   */
  public function priceMember(PricingSubject $subject, PricingContext $context): MemberPriceContribution {
    // No type selected yet (e.g. the form's first render) or a type that
    // no longer resolves - price as 0, exactly like the original
    // getMemberPrice(), which only ever looked up a price inside an
    // `if (!empty($memberType))` guard and otherwise left it at 0.
    $price = $context->typePrice($subject->memberType) ?? 0.0;

    $days = $context->typeDefaultDays($subject->memberType);
    $daysDesc = '';
    $dayMap = $context->typeDays($subject->memberType);

    if ($dayMap !== NULL) {
      $dayCodes = [];
      $dayNames = [];
      // If the day code equal to the type code itself was "checked", the
      // whole-weekend option was selected - full price, no day breakdown.
      if (in_array($subject->memberType, $subject->selectedDayCodes, TRUE)) {
        $daysPrice = $price;
      }
      else {
        $daysPrice = 0.0;
        foreach ($dayMap as $dayCode => $dayOptions) {
          if (in_array($dayCode, $subject->selectedDayCodes, TRUE)) {
            $daysPrice += (float) $dayOptions->price;
            $dayCodes[] = $dayCode;
            $dayNames[] = $dayOptions->name;
          }
        }
      }
      if (!empty($dayCodes) && $daysPrice < $price) {
        $price = $daysPrice;
        $days = implode('|', $dayCodes);
        $daysDesc = implode(', ', $dayNames);
      }
    }

    // Make sure price can never be negative.
    if ($price < 0) {
      $price = 0.0;
    }

    return new MemberPriceContribution(
      [new PriceLine('base_type', 'base', $price, (string) $this->label())],
      $days,
      $daysDesc,
    );
  }

}
