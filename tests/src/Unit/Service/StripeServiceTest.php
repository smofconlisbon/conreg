<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit\Service;

use Drupal\conreg\Service\StripeService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\key\Entity\Key;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidArgumentException;

/**
 * Tests StripeService's Key module resolution and key verification.
 */
#[Group('conreg')]
class StripeServiceTest extends UnitTestCase {

  /**
   * An empty Key ID resolves to an empty string, without touching the repo.
   */
  public function testResolveKeyReturnsEmptyStringWhenKeyIdEmpty(): void {
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->expects($this->never())->method('getKey');

    $service = $this->buildService($keyRepository);

    $this->assertSame('', $service->resolveKey(''));
    $this->assertSame('', $service->resolveKey(NULL));
  }

  /**
   * A Key ID that doesn't resolve to an entity resolves to an empty string.
   */
  public function testResolveKeyReturnsEmptyStringWhenKeyMissing(): void {
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->with('missing_key')->willReturn(NULL);

    $service = $this->buildService($keyRepository);

    $this->assertSame('', $service->resolveKey('missing_key'));
  }

  /**
   * A Key whose provider throws resolves to an empty string.
   */
  public function testResolveKeyReturnsEmptyStringWhenKeyProviderFails(): void {
    $key = $this->createMock(Key::class);
    $key->method('getKeyValue')->willThrowException(new \RuntimeException('Key provider failure.'));

    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->with('failing_key')->willReturn($key);

    $service = $this->buildService($keyRepository);

    $this->assertSame('', $service->resolveKey('failing_key'));
  }

  /**
   * Leading/trailing whitespace (e.g. a trailing newline) is trimmed.
   *
   * Key providers such as file or env commonly leave a trailing newline on
   * the stored value; Stripe's SDK rejects a key containing whitespace
   * outright, so it must be trimmed before use rather than passed through.
   */
  public function testResolveKeyTrimsWhitespace(): void {
    $keyRepository = $this->keyRepositoryResolving(['padded_key' => "sk_test_abc123\n"]);

    $service = $this->buildService($keyRepository);

    $this->assertSame('sk_test_abc123', $service->resolveKey('padded_key'));
  }

  /**
   * SetApiKey() resolves the private_key Key ID for the given event.
   */
  public function testSetApiKeyResolvesConfiguredKeyForEvent(): void {
    $keyRepository = $this->keyRepositoryResolving(['stripe_secret_key' => 'sk_test_abc123']);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('payments.private_key')->willReturn('stripe_secret_key');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('conreg.settings.3')->willReturn($config);

    $service = new StripeService($configFactory, $keyRepository);

    // No exception is the only observable signal from setApiKey() itself;
    // resolveKey() is asserted directly elsewhere.
    $service->setApiKey(3);
    $this->addToAssertionCount(1);
  }

  /**
   * Missing public or secret keys fail verification without an API call.
   */
  public function testVerifyKeysFailsWhenEitherKeyUnresolved(): void {
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->willReturn(NULL);

    $service = $this->buildService($keyRepository);

    $result = $service->verifyKeys('public_key_id', 'secret_key_id');

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('must both be configured', $result['message']);
  }

  /**
   * A secret key Stripe's API rejects is reported as invalid, not thrown.
   */
  public function testVerifyKeysFailsWhenStripeRejectsSecretKey(): void {
    $keyRepository = $this->keyRepositoryResolving([
      'public_key_id' => 'pk_test_abc',
      'secret_key_id' => 'sk_test_xyz',
    ]);
    $service = $this->buildService($keyRepository, new ApiConnectionException('Simulated Stripe rejection.'));

    $result = $service->verifyKeys('public_key_id', 'secret_key_id');

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Invalid secret key', $result['message']);
  }

  /**
   * A key the Stripe client rejects locally is reported as invalid, not thrown.
   *
   * The Stripe SDK validates key format in its own constructor - before
   * any request is made - and throws InvalidArgumentException rather than
   * ApiErrorException, e.g. for a key containing whitespace. verifyKeys()
   * must catch this too, or a malformed key breaks the admin form instead
   * of showing an invalid-key message.
   */
  public function testVerifyKeysFailsWhenStripeClientRejectsKeyLocally(): void {
    $keyRepository = $this->keyRepositoryResolving([
      'public_key_id' => 'pk_test_abc',
      'secret_key_id' => 'sk_test_xyz',
    ]);
    $service = $this->buildService($keyRepository, new InvalidArgumentException('api_key cannot contain whitespace'));

    $result = $service->verifyKeys('public_key_id', 'secret_key_id');

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Invalid secret key', $result['message']);
  }

  /**
   * A test-mode public key with a live-mode secret key is a mismatch.
   */
  public function testVerifyKeysFailsOnModeMismatch(): void {
    $keyRepository = $this->keyRepositoryResolving([
      'public_key_id' => 'pk_test_abc',
      'secret_key_id' => 'sk_live_xyz',
    ]);
    $service = $this->buildService($keyRepository);

    $result = $service->verifyKeys('public_key_id', 'secret_key_id');

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('different modes', $result['message']);
  }

  /**
   * Matching-mode keys that Stripe accepts verify successfully.
   */
  public function testVerifyKeysSucceedsForMatchingModeKeys(): void {
    $keyRepository = $this->keyRepositoryResolving([
      'public_key_id' => 'pk_test_abc',
      'secret_key_id' => 'sk_test_xyz',
    ]);
    $service = $this->buildService($keyRepository);

    $result = $service->verifyKeys('public_key_id', 'secret_key_id');

    $this->assertTrue($result['valid']);
  }

  /**
   * The success message doesn't overclaim what the check covers.
   *
   * There's no way to confirm the public and secret keys belong to the
   * same Stripe account server-side - a publishable key isn't valid for
   * authenticated backend requests - so a same-mode pair from two
   * different, unrelated Stripe accounts still passes. The message must
   * say so rather than imply the pair itself was verified.
   */
  public function testVerifyKeysSuccessMessageDoesNotClaimAccountPairing(): void {
    $keyRepository = $this->keyRepositoryResolving([
      'public_key_id' => 'pk_test_abc',
      'secret_key_id' => 'sk_test_xyz',
    ]);
    $service = $this->buildService($keyRepository);

    $result = $service->verifyKeys('public_key_id', 'secret_key_id');

    $this->assertStringContainsString('does not confirm', $result['message']);
  }

  /**
   * Keys matching each other but not the configured payment mode fail.
   */
  public function testVerifyKeysFailsWhenModeDoesNotMatchConfiguredMode(): void {
    $keyRepository = $this->keyRepositoryResolving([
      'public_key_id' => 'pk_test_abc',
      'secret_key_id' => 'sk_test_xyz',
    ]);
    $service = $this->buildService($keyRepository);

    $result = $service->verifyKeys('public_key_id', 'secret_key_id', 'Live');

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('payment mode', $result['message']);
  }

  /**
   * Keys matching both each other and the configured payment mode succeed.
   */
  public function testVerifyKeysSucceedsWhenModeMatchesConfiguredMode(): void {
    $keyRepository = $this->keyRepositoryResolving([
      'public_key_id' => 'pk_live_abc',
      'secret_key_id' => 'sk_live_xyz',
    ]);
    $service = $this->buildService($keyRepository);

    $result = $service->verifyKeys('public_key_id', 'secret_key_id', 'Live');

    $this->assertTrue($result['valid']);
  }

  /**
   * Builds a mocked key repository resolving the given ID => value map.
   */
  protected function keyRepositoryResolving(array $keyMap): KeyRepositoryInterface {
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->willReturnCallback(
      function (string $id) use ($keyMap): ?Key {
        if (!isset($keyMap[$id])) {
          return NULL;
        }
        $key = $this->createMock(Key::class);
        $key->method('getKeyValue')->willReturn($keyMap[$id]);
        return $key;
      }
    );
    return $keyRepository;
  }

  /**
   * Builds a StripeService whose live connectivity check is stubbed out.
   *
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The mocked key repository.
   * @param \Throwable|null $connectivityException
   *   An exception for the stubbed connectivity check to throw, or NULL for
   *   it to succeed.
   */
  protected function buildService(KeyRepositoryInterface $keyRepository, ?\Throwable $connectivityException = NULL): StripeService {
    return new class($this->createMock(ConfigFactoryInterface::class), $keyRepository, $connectivityException) extends StripeService {

      public function __construct(
        ConfigFactoryInterface $configFactory,
        KeyRepositoryInterface $keyRepository,
        protected ?\Throwable $connectivityException,
      ) {
        parent::__construct($configFactory, $keyRepository);
      }

      /**
       * Simulates Stripe's response instead of making a real API call.
       */
      protected function checkSecretKeyConnectivity(string $secretKey): void {
        if ($this->connectivityException) {
          throw $this->connectivityException;
        }
      }

    };
  }

}
