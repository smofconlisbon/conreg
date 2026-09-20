<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Addons;
use Drupal\conreg\ConregConfig;
use Drupal\conreg\Member;
use Drupal\conreg\Payment;
use Drupal\conreg\PaymentLine;
use Drupal\conreg\UpgradeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Marks payments/members paid once Stripe confirms a Checkout Session.
 *
 * Shared between Checkout (which checks the specific session a member is
 * returning from) and the cron reconciliation sweep (which catches payments
 * whose member never returned to the checkout route after paying), so both
 * paths mark a payment complete the same way.
 */
class PaymentCompletionService {

  /**
   * Don't check a payment less than this many seconds after it was created.
   *
   * Avoids the cron sweep racing a checkout that's still legitimately in
   * progress (e.g. the member is mid-payment on Stripe's hosted page).
   */
  const MIN_AGE_SECONDS = 600;

  /**
   * Stop checking a payment once it's this many seconds old.
   *
   * Beyond this, a still-unpaid payment is treated as an abandoned
   * registration rather than one worth polling Stripe for indefinitely.
   */
  const MAX_AGE_SECONDS = 7 * 86400;

  /**
   * Maximum number of pending payments to check per cron run.
   *
   * Bounds how many Stripe API calls a single cron run can make.
   */
  const BATCH_LIMIT = 50;

  public function __construct(
    protected MemberStorage $memberStorage,
    protected RegistrationConfirmationMailer $confirmationMailer,
    protected UpgradeStorage $upgradeStorage,
    protected PaymentStorage $paymentStorage,
    protected StripeServiceInterface $stripeService,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConregOptions $conregOptions,
  ) {}

  /**
   * Whether a Stripe Checkout Session reports as paid.
   */
  public function isSessionPaid(object $session): bool {
    return ($session->status ?? NULL) === 'complete' || ($session->payment_status ?? NULL) === 'paid';
  }

  /**
   * Whether a Stripe Checkout Session is still open (unpaid, unexpired).
   */
  public function isSessionOpen(object $session): bool {
    return ($session->status ?? NULL) === 'open';
  }

  /**
   * Marks a payment (and its members/upgrades) complete from a paid session.
   *
   * Safe to call more than once for the same payment/session - each step
   * only acts if it hasn't already been done.
   */
  public function markSessionComplete(Payment $payment, object $session, int $eid, bool $autoApprove): void {
    // Only update payment if not already paid.
    if (empty($payment->paidDate)) {
      $payment->paidDate = $this->time->getRequestTime();
      $payment->paymentMethod = "Stripe";
      $payment->paymentRef = $session->payment_intent;
      $payment->save();
    }

    Addons::markPaid($payment->getId(), $session->payment_intent);

    foreach ($payment->paymentLines as $line) {
      $this->processPaymentLine($line, $session, $eid, $autoApprove);
    }
  }

  /**
   * Process a line of payment information from a paid Stripe session.
   */
  public function processPaymentLine(PaymentLine $line, object $session, int $eid, bool $autoApprove): void {
    $config = ConregConfig::getConfig($eid);
    switch ($line->type) {
      case "member":
        // Only update member if not already paid.
        $member = Member::loadMember((int) $line->mid);
        if (is_object($member) && !$member->is_paid && !$member->is_deleted) {
          $member->is_paid = 1;
          if ($autoApprove) {
            $member->is_approved = 1;
            $max_member = $this->memberStorage->loadMaxMemberNo($eid);
            $max_member++;
            $member->member_no = $max_member;
          }
          $member->payment_id = $session->payment_intent;
          $member->payment_method = 'Stripe';
          $member->saveMember();

          // If email address populated, send confirmation email.
          if (!empty($member->email)) {
            $this->confirmationMailer->send((array) $member);
          }

          // Check if event has a role to add to user account.
          $add_role = $config->get('member_portal.add_role');
          if ($add_role) {
            $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $member->email]);
            $account = $accounts ? reset($accounts) : NULL;
            // Check if user has role already.
            if ($account instanceof UserInterface && !$account->hasRole($add_role)) {
              // They don't, so we need to add it.
              $account->addRole($add_role);
              $account->save();
            }
          }
        }
        break;

      case "upgrade":
        $member = Member::loadMember((int) $line->mid);
        if (isset($member) && is_object($member) && !$member->is_deleted) {
          $mgr = new UpgradeManager($this->upgradeStorage, $this->memberStorage, $this->time, $this->conregOptions, $member->eid);
          if ($mgr->loadUpgrades($member->mid, 0)) {
            $payment = Payment::loadBySessionId($session->id);
            if (!is_null($payment)) {
              $mgr->completeUpgrades($payment->paymentAmount, $payment->paymentMethod, $payment->paymentRef);
            }
          }
        }
        break;
    }
  }

  /**
   * If no charge for payment line, just mark paid.
   */
  public function processWithoutPayment(PaymentLine $line, int $eid, bool $autoApprove): void {
    switch ($line->type) {
      case "member":
        // Only update member if not already paid.
        $member = Member::loadMember((int) $line->mid);
        if (is_object($member) && !$member->is_paid && !$member->is_deleted) {
          $member->is_paid = 1;
          if ($autoApprove) {
            $member->is_approved = 1;
            $max_member = $this->memberStorage->loadMaxMemberNo($eid);
            $max_member++;
            $member->member_no = $max_member;
          }
          $member->payment_id = 'N/A';
          $member->payment_method = 'Free';
          $member->saveMember();

          // If email address populated, send confirmation email.
          if (!empty($member->email)) {
            $this->confirmationMailer->send((array) $member);
          }
        }
        break;
    }
  }

  /**
   * Re-checks unpaid payments whose member never returned to Checkout.
   *
   * Called from cron. A member who is redirected back to Stripe's hosted
   * checkout, pays, but then closes the tab (or loses connectivity) before
   * their browser returns to the checkout route never triggers the normal
   * per-visit session check - this sweep is what eventually catches that.
   */
  public function reconcilePendingPayments(): void {
    $now = $this->time->getRequestTime();
    $payments = $this->paymentStorage->loadPendingPayments(
      $now - self::MIN_AGE_SECONDS,
      $now - self::MAX_AGE_SECONDS,
      self::BATCH_LIMIT,
    );

    $currentEid = NULL;
    foreach ($payments as $row) {
      $payment = Payment::load((int) $row['payid']);
      if (is_null($payment) || !empty($payment->paidDate)) {
        continue;
      }

      $eid = $this->resolveEid($payment);
      if (is_null($eid)) {
        continue;
      }

      $sessionIds = $this->paymentStorage->loadSessionIds($payment->payId);
      if (empty($sessionIds)) {
        continue;
      }

      if ($eid !== $currentEid) {
        $this->stripeService->setApiKey($eid);
        $currentEid = $eid;
      }

      $session = $this->stripeService->retrieveSession($sessionIds[0]);
      if ($session && $this->isSessionPaid($session)) {
        $config = ConregConfig::getConfig($eid);
        $autoApprove = (bool) ($config->get('payments.auto_approve') ?: FALSE);
        $this->markSessionComplete($payment, $session, $eid, $autoApprove);
      }
    }
  }

  /**
   * Derives the event ID a payment belongs to, from its first member line.
   */
  protected function resolveEid(Payment $payment): ?int {
    $mid = $payment->paymentLines[0]->mid ?? NULL;
    if (empty($mid)) {
      return NULL;
    }
    $member = Member::loadMember((int) $mid);
    return $member?->eid ? (int) $member->eid : NULL;
  }

}
