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

}
