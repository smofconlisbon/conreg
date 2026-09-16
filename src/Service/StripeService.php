<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\Exception\ExceptionInterface;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository, used to resolve Stripe keys stored in the Key
   *   module rather than as plain config values.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'key.repository')]
    protected KeyRepositoryInterface $keyRepository,
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
    $this->client = new StripeClient($this->resolveKey($config->get('payments.private_key')));
  }

  /**
   * {@inheritdoc}
   */
  public function resolveKey(?string $keyId): string {
    if (empty($keyId)) {
      return '';
    }
    try {
      return trim((string) ($this->keyRepository->getKey($keyId)?->getKeyValue() ?? ''));
    }
    catch (\Throwable) {
      return '';
    }
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

  /**
   * {@inheritdoc}
   */
  public function useMockCheckoutPage(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function verifyKeys(string $publicKeyId, string $secretKeyId, ?string $expectedMode = NULL): array {
    $publicKey = $this->resolveKey($publicKeyId);
    $secretKey = $this->resolveKey($secretKeyId);

    if ($publicKey === '' || $secretKey === '') {
      return ['valid' => FALSE, 'message' => 'Public and secret keys must both be configured.'];
    }

    try {
      $this->checkSecretKeyConnectivity($secretKey);
    }
    catch (ExceptionInterface $e) {
      // Covers both API-rejected keys (ApiErrorException) and locally
      // invalid ones the client rejects before any request is made, e.g.
      // InvalidArgumentException for a key containing whitespace.
      return ['valid' => FALSE, 'message' => 'Invalid secret key: ' . $e->getMessage()];
    }

    $keysMode = $this->keyMode($secretKey);
    if ($this->keyMode($publicKey) !== $keysMode) {
      return ['valid' => FALSE, 'message' => 'Public and secret keys are for different modes (test/live).'];
    }

    if ($expectedMode !== NULL && strtolower($expectedMode) !== $keysMode) {
      return [
        'valid' => FALSE,
        'message' => "Both keys are in $keysMode mode, but the payment mode above is set to $expectedMode.",
      ];
    }

    return [
      'valid' => TRUE,
      'message' => 'Secret key authenticated with Stripe, and both keys are in the same mode' .
      ($expectedMode !== NULL ? ', matching the payment mode above' : '') .
      '. This does not confirm the public and secret keys belong to the same Stripe account - verify that with a real test payment before going live.',
    ];
  }

  /**
   * Makes a cheap live API call to confirm the secret key works.
   *
   * Isolated in its own method (rather than inlined in verifyKeys()) so
   * tests can override it and avoid a real network call to Stripe.
   *
   * @param string $secretKey
   *   The resolved Stripe secret key.
   *
   * @throws \Stripe\Exception\ExceptionInterface
   *   When Stripe rejects the key, or the client rejects it locally
   *   (e.g. a malformed value) before making any request.
   */
  protected function checkSecretKeyConnectivity(string $secretKey): void {
    (new StripeClient($secretKey))->balance->retrieve();
  }

  /**
   * Determines whether a Stripe key is a test or live mode key.
   *
   * @param string $key
   *   The Stripe key value.
   *
   * @return string
   *   'test', 'live', or 'unknown' if the key doesn't match Stripe's
   *   published key formats.
   */
  protected function keyMode(string $key): string {
    return match (TRUE) {
      (bool) preg_match('/^[a-z]{2}_test_/', $key) => 'test',
      (bool) preg_match('/^[a-z]{2}_live_/', $key) => 'live',
      default => 'unknown',
    };
  }

}
