<?php

namespace Drupal\conreg\Hook;

use Drupal\conreg\Service\PaymentCompletionService;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Cron hook for reconciling abandoned Stripe payments.
 *
 * Kept in its own class (rather than alongside CronHooks's other, unrelated
 * cron task) so that class's much lighter dependencies aren't pulled in
 * just to run this one, and vice versa - PaymentCompletionService brings in
 * the confirmation mailer, member/upgrade storage, etc, which a test or
 * environment exercising only the other cron task shouldn't need to
 * satisfy.
 */
class PaymentReconciliationCronHooks {

  public function __construct(
    protected PaymentCompletionService $paymentCompletion,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Re-checks unpaid payments whose member never returned to the checkout
   * route after paying on Stripe (closed tab, network drop, etc.), since
   * nothing else would ever re-check Stripe for them otherwise - Checkout
   * only checks a payment's session when that route is visited.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->paymentCompletion->reconcilePendingPayments();
  }

}
