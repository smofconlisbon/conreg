<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\StripeClient;

/**
 * Service for handling Stripe payment operations.
 *
 * This service provides a wrapper around Stripe API calls to allow for
 * easier testing and mocking of payment functionality.
 */
class StripeService implements StripeServiceInterface {

  /**
   * Constructs a new StripeService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The Stripe client.
   *
   * @var \Stripe\StripeClient
   */
  protected StripeClient $client;

  /**
   * Sets the Stripe API key for the given event.
   *
   * @param int $eid
   *   The event ID.
   */
  public function setApiKey(int $eid): void {
    $config = $this->configFactory->get('conreg.settings.' . $eid);
    $this->client = new StripeClient($config->get('payments.private_key'));
  }

  /**
   * Creates a Stripe checkout session.
   *
   * @param array $sessionData
   *   The session data to pass to Stripe.
   *
   * @return \Stripe\Checkout\Session|null
   *   The created Stripe session.
   */
  public function createCheckoutSession(array $sessionData): ?Session {
    $session = $this->client->checkout->sessions->create($sessionData);
    if (get_class($session) == 'Stripe\Checkout\Session') {
      return $session;
    }
    return NULL;
  }

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
  public function getEvents(string $eventType, int $sinceTimestamp): Collection {
    return $this->client->events->all([
      'type' => $eventType,
      'created' => [
        'gte' => $sinceTimestamp,
      ],
    ]);
  }

}
