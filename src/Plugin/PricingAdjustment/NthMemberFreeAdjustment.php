<?php

declare(strict_types=1);

namespace Drupal\conreg\Plugin\PricingAdjustment;

use Drupal\conreg\Pricing\Attribute\PricingAdjustment as PricingAdjustmentAttribute;
use Drupal\conreg\Pricing\PriceAdjustment;
use Drupal\conreg\Pricing\PricingAdjustmentPluginBase;
use Drupal\conreg\Pricing\PricingContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Makes every Nth most-expensive eligible member free.
 *
 * Ported verbatim from the original Registration::getAllMemberPrices()'s
 * sort/modulo logic: members with a base price greater than zero are
 * sorted by base price descending (ties broken by member number
 * ascending), and every (discountFreeEvery + 1)th member in that order has
 * their base price zeroed out - add-ons are left untouched.
 */
#[PricingAdjustmentAttribute(
  id: 'nth_member_free',
  label: new TranslatableMarkup('Every Nth member free'),
  weight: 0,
)]
final class NthMemberFreeAdjustment extends PricingAdjustmentPluginBase {

  /**
   * {@inheritdoc}
   */
  public function adjust(array $memberResults, PricingContext $context): array {
    if (!$context->discountEnabled) {
      return [];
    }

    $eligible = [];
    foreach ($memberResults as $result) {
      if ($result->basePrice() > 0) {
        $eligible[] = $result;
      }
    }

    usort($eligible, function ($a, $b) {
      if ($a->basePrice() === $b->basePrice()) {
        return $a->memberNo <=> $b->memberNo;
      }
      return $b->basePrice() <=> $a->basePrice();
    });

    $adjustments = [];
    $count = 0;
    foreach ($eligible as $result) {
      $count++;
      if ($count % ($context->discountFreeEvery + 1) === 0) {
        $adjustments[] = new PriceAdjustment(
          $result->memberNo,
          -$result->basePrice(),
          'nth_member_free',
          'nth_member_free',
        );
      }
    }

    return $adjustments;
  }

}
