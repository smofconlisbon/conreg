<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

/**
 * Interface for Stripe payment services.
 */
interface StripeServiceInterface {

  /**
   * Sets the Stripe API key for the given event.
   *
   * The secret key is resolved from the Key module using the Key ID stored
   * in the event's payments.private_key config.
   *
   * @param int $eid
   *   The event ID.
   */
  public function setApiKey(int $eid): void;

  /**
   * Creates a Stripe checkout session.
   *
   * @param array $sessionData
   *   The session data to pass to Stripe.
   *
   * @return \Stripe\Checkout\Session
   *   The created Stripe session.
   */
  public function createCheckoutSession(array $sessionData);

  /**
   * Retrieves Stripe events of a specific type.
   *
   * @param string $eventType
   *   The type of event to retrieve.
   * @param int $sinceTimestamp
   *   The timestamp to retrieve events since.
   *
   * @return \Stripe\Collection
   *   The collection of Stripe events.
   */
  public function getEvents(string $eventType, int $sinceTimestamp);

  /**
   * Resolves a Stripe Key ID (from config) to its actual key value.
   *
   * @param string|null $keyId
   *   The Key entity ID, or an empty value if none is configured.
   *
   * @return string
   *   The resolved key value, or an empty string if the key is not
   *   configured or cannot be resolved.
   */
  public function resolveKey(?string $keyId): string;

  /**
   * Resolves the public/secret key pair and checks them against Stripe.
   *
   * Makes a live API call using the secret key, and cross-checks the
   * public key's test/live mode against the secret key's mode and, if
   * given, against $expectedMode.
   *
   * This does NOT confirm the public and secret keys belong to the same
   * Stripe account - Stripe has no server-side API to check that, since a
   * publishable key isn't valid for authenticated backend requests. A pair
   * of unrelated but same-mode keys (e.g. a secret key from one Stripe
   * account and a publishable key from another) will pass this check.
   *
   * @param string $publicKeyId
   *   The Key ID storing the publishable key.
   * @param string $secretKeyId
   *   The Key ID storing the secret key.
   * @param string|null $expectedMode
   *   The event's configured payment mode ('Test' or 'Live'), or NULL to
   *   skip checking the keys against it.
   *
   * @return array
   *   An array with keys:
   *   - valid: (bool) Whether the keys passed the checks above.
   *   - message: (string) A human-readable status message, scoped to what
   *     was actually checked.
   */
  public function verifyKeys(string $publicKeyId, string $secretKeyId, ?string $expectedMode = NULL): array;

}
