<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Checkout;
use Drupal\conreg\Service\StripeServiceInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that Checkout::buildForm() reuses a still-open Stripe session.
 *
 * Before this, every unpaid visit to the checkout route minted a brand new
 * Stripe Checkout Session, regardless of whether an earlier one for the
 * same payment was still open - a double-click, a page refresh during the
 * "Transferring to Stripe" wait, or reopening the payment link in a second
 * tab could each create a separate live session for the same payment,
 * risking the member paying more than once. buildForm() now checks the
 * most recently created session directly and reuses it when it's still
 * open and for the same amount, rather than always creating a new one.
 */
#[CoversClass(Checkout::class)]
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class CheckoutSessionReuseTest extends KernelTestBase {

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
   * The payment ID under test.
   */
  protected const PAYID = 1;

  /**
   * The payment's random key, required to load it via Checkout::buildForm().
   */
  protected const RANDOM_KEY = 4242;

  /**
   * The member price line's amount (in the event's major currency unit).
   */
  protected const AMOUNT = 50.0;

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

    Database::getConnection()->insert('conreg_payments')
      ->fields([
        'payid' => self::PAYID,
        'random_key' => self::RANDOM_KEY,
        'created_date' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    // member_type is deliberately left at its default (empty) value, so
    // PricingService::recomputeForPayment() can't resolve a type for it and
    // leaves the payment line's amount untouched - keeping the amount used
    // to create/match Stripe sessions predictable for this test.
    Database::getConnection()->insert('conreg_members')
      ->fields([
        'mid' => 1,
        'eid' => 1,
        'lead_mid' => 1,
        'language' => 'en',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => '',
        'is_deleted' => 0,
        'join_date' => \Drupal::time()->getRequestTime(),
        'update_date' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    Database::getConnection()->insert('conreg_payment_lines')
      ->fields([
        'payid' => self::PAYID,
        'mid' => 1,
        'payment_type' => 'member',
        'line_desc' => 'Test membership',
        'amount' => self::AMOUNT,
      ])
      ->execute();
  }

  /**
   * Builds a Checkout form instance for direct method calls.
   */
  protected function createCheckoutForm(): Checkout {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(Checkout::class);
  }

  /**
   * Loading the checkout page twice reuses the same session, not a new one.
   */
  public function testSecondVisitReusesOpenSession(): void {
    $expectedAmountTotal = (int) round(self::AMOUNT * 100);

    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->method('useMockCheckoutPage')->willReturn(TRUE);
    $stripeService->expects($this->once())
      ->method('createCheckoutSession')
      ->willReturn((object) ['id' => 'cs_first']);
    $stripeService->expects($this->once())
      ->method('retrieveSession')
      ->with('cs_first')
      ->willReturn((object) [
        'id' => 'cs_first',
        'status' => 'open',
        'payment_status' => 'unpaid',
        'amount_total' => $expectedAmountTotal,
      ]);
    $this->container->set('conreg.stripe_service', $stripeService);

    // First visit: no session exists yet, so one is created.
    $form1 = $this->createCheckoutForm()->buildForm([], new FormState(), self::PAYID, self::RANDOM_KEY, '');
    $this->assertArrayHasKey('mock_checkout', $form1);

    // Second visit: the first session is still open, for the same amount,
    // so it's reused rather than a second one being created.
    // (createCheckoutSession()'s and retrieveSession()'s once() expectations
    // above, shared across both calls via the same mock instance, are what
    // actually enforce this.)
    $form2 = $this->createCheckoutForm()->buildForm([], new FormState(), self::PAYID, self::RANDOM_KEY, '');
    $this->assertArrayHasKey('mock_checkout', $form2);

    $sessionRows = Database::getConnection()->select('conreg_payment_sessions', 's')
      ->fields('s', ['session_id'])
      ->condition('payid', self::PAYID)
      ->execute()
      ->fetchCol();
    $this->assertSame(['cs_first'], $sessionRows, 'Only the first session should ever be recorded for this payment.');
  }

  /**
   * A session Stripe no longer reports open is not reused.
   *
   * If the recorded session has expired (or Stripe can't be reached for
   * it), buildForm() must fall back to creating a fresh session rather
   * than reusing a stale/unusable one.
   */
  public function testExpiredSessionIsNotReused(): void {
    $expectedAmountTotal = (int) round(self::AMOUNT * 100);

    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->method('useMockCheckoutPage')->willReturn(TRUE);
    $stripeService->expects($this->exactly(2))
      ->method('createCheckoutSession')
      ->willReturnOnConsecutiveCalls(
        (object) ['id' => 'cs_first'],
        (object) ['id' => 'cs_second'],
      );
    $stripeService->expects($this->once())
      ->method('retrieveSession')
      ->with('cs_first')
      ->willReturn((object) [
        'id' => 'cs_first',
        'status' => 'expired',
        'payment_status' => 'unpaid',
        'amount_total' => $expectedAmountTotal,
      ]);
    $this->container->set('conreg.stripe_service', $stripeService);

    $this->createCheckoutForm()->buildForm([], new FormState(), self::PAYID, self::RANDOM_KEY, '');
    $this->createCheckoutForm()->buildForm([], new FormState(), self::PAYID, self::RANDOM_KEY, '');

    $sessionRows = Database::getConnection()->select('conreg_payment_sessions', 's')
      ->fields('s', ['session_id'])
      ->condition('payid', self::PAYID)
      ->execute()
      ->fetchCol();
    $this->assertSame(['cs_first', 'cs_second'], $sessionRows);
  }

}
