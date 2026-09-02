<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\conreg\Payment;
use Drupal\conreg\Pricing\PricingContext;
use Drupal\conreg\Pricing\PricingRecomputeResult;
use Drupal\conreg\Pricing\PricingResult;

/**
 * Calculates registration pricing via the pricing plugin architecture.
 */
interface PricingServiceInterface {

  /**
   * Prices a set of members, applying whole-registration adjustments.
   *
   * @param \Drupal\conreg\Pricing\PricingContext $context
   *   The shared registration context.
   * @param \Drupal\conreg\Pricing\PricingSubject[] $subjects
   *   The members to price, keyed by member number.
   */
  public function priceRegistration(PricingContext $context, array $subjects): PricingResult;

  /**
   * Recomputes a not-yet-paid payment's prices from persisted member data.
   *
   * Used at checkout time to guard against an admin having edited a
   * member's pricing-relevant details (e.g. their type) after registration
   * but before payment completed. Only 'member' type payment lines are
   * reconciled; add-on line amounts are left as originally persisted.
   *
   * @param \Drupal\conreg\Payment $payment
   *   The already-loaded payment to recompute. Must not be modified by the
   *   caller before this call if drift detection is to be meaningful.
   */
  public function recomputeForPayment(Payment $payment): PricingRecomputeResult;

}
