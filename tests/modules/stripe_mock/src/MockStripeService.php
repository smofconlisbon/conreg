<?php

declare(strict_types=1);

namespace Drupal\stripe_mock;

use Drupal\conreg\Service\StripeServiceInterface;

/**
 * Mock Stripe service for FunctionalJavascript tests.
 *
 * Always reports the same fixed checkout session id as both created and
 * completed, so Checkout::buildForm() marks the payment paid the next time
 * it checks the session after it's created (i.e. once the test presses the
 * mock checkout page's "Pay now" button and returns to the checkout route).
 * Also requests the local mock checkout page (see useMockCheckoutPage()),
 * since a test browser cannot complete a real Stripe-hosted checkout.
 */
class MockStripeService implements StripeServiceInterface {

  /**
   * The fake checkout session id used throughout the mocked flow.
   */
  const SESSION_ID = 'cs_test_123';

  /**
   * The fake Stripe payment intent id used throughout the mocked flow.
   */
  const PAYMENT_INTENT_ID = 'pi_123456';

  /**
   * {@inheritDoc}
   */
  public function setApiKey(int $eid): void {}

  /**
   * {@inheritDoc}
   */
  public function createCheckoutSession(array $sessionData) {
    return (object) ['id' => self::SESSION_ID];
  }

  /**
   * {@inheritDoc}
   */
  public function retrieveSession(string $sessionId): ?object {
    if ($sessionId !== self::SESSION_ID) {
      return NULL;
    }
    return (object) [
      'id' => self::SESSION_ID,
      'status' => 'complete',
      'payment_status' => 'paid',
      'payment_intent' => self::PAYMENT_INTENT_ID,
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function resolveKey(?string $keyId): string {
    return 'pk_test_mock';
  }

  /**
   * {@inheritDoc}
   */
  public function verifyKeys(string $publicKeyId, string $secretKeyId, ?string $expectedMode = NULL): array {
    return ['valid' => TRUE, 'message' => 'mocked'];
  }

  /**
   * {@inheritDoc}
   */
  public function useMockCheckoutPage(): bool {
    return TRUE;
  }

}
