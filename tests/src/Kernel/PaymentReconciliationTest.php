<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Member;
use Drupal\conreg\Payment;
use Drupal\conreg\Service\PaymentCompletionService;
use Drupal\conreg\Service\StripeServiceInterface;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests PaymentCompletionService::reconcilePendingPayments().
 *
 * This is the cron sweep that catches a member who paid on Stripe's hosted
 * checkout but whose browser never returned to the checkout route
 * afterwards (closed tab, network drop, etc.) - the one gap nothing else
 * in the fix for #3596648 covers, since everything else only checks a
 * payment's session when that route is actually visited.
 */
#[CoversClass(PaymentCompletionService::class)]
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class PaymentReconciliationTest extends KernelTestBase {

  protected const CURRENT_TIME = 1700000000;

  /**
   * Modules to enable for this test.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * Session IDs that reconcilePendingPayments() actually asked Stripe about.
   *
   * @var string[]
   */
  protected array $checkedSessionIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'datetime']);
    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_options',
      'conreg_payments',
      'conreg_payment_lines',
      'conreg_payment_sessions',
      'conreg_upgrades',
    ]);
    $this->installConfig(['conreg']);

    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::CURRENT_TIME);
    $this->container->set('datetime.time', $time);
  }

  /**
   * Inserts a payment for the reconciliation sweep to consider.
   *
   * Includes one member payment line and a recorded Stripe session.
   *
   * @param int $payId
   *   The payment ID.
   * @param int $mid
   *   The member ID for its single payment line.
   * @param int $createdDate
   *   The payment's `created_date` timestamp.
   * @param string $sessionId
   *   The Stripe Checkout Session ID recorded against the payment.
   */
  protected function createPendingPayment(int $payId, int $mid, int $createdDate, string $sessionId): void {
    Database::getConnection()->insert('conreg_payments')
      ->fields(['payid' => $payId, 'random_key' => $payId * 111, 'created_date' => $createdDate])
      ->execute();
    Database::getConnection()->insert('conreg_payment_sessions')
      ->fields(['payid' => $payId, 'session_id' => $sessionId])
      ->execute();
    Database::getConnection()->insert('conreg_members')
      ->fields([
        'mid' => $mid,
        'eid' => 1,
        'lead_mid' => $mid,
        'language' => 'en',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => '',
        'is_deleted' => 0,
        'join_date' => $createdDate,
        'update_date' => $createdDate,
      ])
      ->execute();
    Database::getConnection()->insert('conreg_payment_lines')
      ->fields([
        'payid' => $payId,
        'mid' => $mid,
        'payment_type' => 'member',
        'line_desc' => 'Test membership',
        'amount' => 50.0,
      ])
      ->execute();
  }

  /**
   * Tests the sweep's three outcomes: marked paid, left open, and skipped.
   *
   * A paid session marks the payment and member paid; an open one doesn't;
   * a too-recent payment is never even checked with Stripe.
   */
  public function testReconcileMarksPaidChecksOpenAndSkipsTooRecent(): void {
    // Old enough to check, and Stripe now reports it paid.
    $this->createPendingPayment(1, 1, self::CURRENT_TIME - 1000, 'sess_paid');
    // Old enough to check, but Stripe still reports it open/unpaid.
    $this->createPendingPayment(2, 2, self::CURRENT_TIME - 1000, 'sess_open');
    // Too recent (younger than the 10-minute minimum age) - must not be
    // checked at all, even though Stripe would report it paid if asked.
    $this->createPendingPayment(3, 3, self::CURRENT_TIME - 100, 'sess_too_new');

    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->method('retrieveSession')->willReturnCallback(function (string $sessionId) {
      $this->checkedSessionIds[] = $sessionId;
      return match ($sessionId) {
        'sess_paid' => (object) [
          'id' => 'sess_paid',
          'status' => 'complete',
          'payment_status' => 'paid',
          'payment_intent' => 'pi_paid',
        ],
        'sess_open' => (object) [
          'id' => 'sess_open',
          'status' => 'open',
          'payment_status' => 'unpaid',
        ],
        default => throw new \LogicException("Unexpected session lookup: $sessionId"),
      };
    });
    $this->container->set('conreg.stripe_service', $stripeService);

    $this->container->get(PaymentCompletionService::class)->reconcilePendingPayments();

    // Payment 1: marked paid, member marked paid.
    $payment1 = Payment::load(1);
    $this->assertNotEmpty($payment1->paidDate);
    $this->assertSame('pi_paid', $payment1->paymentRef);
    $this->assertEquals(1, Member::loadMember(1)->is_paid);

    // Payment 2: still open on Stripe's side, so left untouched.
    $payment2 = Payment::load(2);
    $this->assertEmpty($payment2->paidDate);
    $this->assertEquals(0, Member::loadMember(2)->is_paid);

    // Payment 3: too recent to have been checked at all.
    $payment3 = Payment::load(3);
    $this->assertEmpty($payment3->paidDate);
    $this->assertEquals(0, Member::loadMember(3)->is_paid);
    $this->assertNotContains('sess_too_new', $this->checkedSessionIds);

    // Confirms 1 and 2 actually were checked (not just incidentally correct).
    $this->assertContains('sess_paid', $this->checkedSessionIds);
    $this->assertContains('sess_open', $this->checkedSessionIds);
  }

}
