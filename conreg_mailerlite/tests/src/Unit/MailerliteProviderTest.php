<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailerlite\Unit;

use Drupal\conreg_mailerlite\Plugin\MailingListProvider\MailerliteProvider;
use Drupal\conreg_mailing_list\Exception\MailingListPermanentException;
use Drupal\conreg_mailing_list\Exception\MailingListTransientException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\key\Entity\Key;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests MailerliteProvider's request handling and failure mapping.
 */
#[Group('conreg')]
class MailerliteProviderTest extends UnitTestCase {

  /**
   * Captured Guzzle request/response history, populated per test.
   */
  protected array $history = [];

  /**
   * The lists endpoint returns the expected id => name map.
   */
  public function testGetListsParsesResponse(): void {
    $provider = $this->createProvider([
      new Response(200, [], json_encode(['data' => [['id' => '123', 'name' => 'My List']]])),
    ]);

    $this->assertSame(['123' => 'My List'], $provider->getLists());
  }

  /**
   * A missing API key is a transient failure, without making a request.
   */
  public function testGetListsThrowsTransientWhenApiKeyMissing(): void {
    $provider = $this->createProvider([], '');

    $this->expectException(MailingListTransientException::class);
    $provider->getLists();
  }

  /**
   * A key ID that doesn't resolve to a Key entity is a transient failure.
   */
  public function testSubscribeThrowsTransientWhenKeyNotFound(): void {
    $provider = $this->createProviderWithMissingKey();

    $this->expectException(MailingListTransientException::class);
    $provider->subscribe('person@example.com', '456', []);
  }

  /**
   * A Key whose provider fails to resolve a value is a transient failure.
   */
  public function testSubscribeThrowsTransientWhenKeyProviderFails(): void {
    $provider = $this->createProviderWithFailingKey();

    $this->expectException(MailingListTransientException::class);
    $provider->subscribe('person@example.com', '456', []);
  }

  /**
   * The subscribe() method posts the expected method, path, and body.
   */
  public function testSubscribeSendsCorrectRequestBody(): void {
    $provider = $this->createProvider([
      new Response(200, [], '{}'),
    ]);

    $provider->subscribe('person@example.com', '456', ['name' => 'Person Name']);

    $this->assertCount(1, $this->history);
    /** @var \GuzzleHttp\Psr7\Request $request */
    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('/api/subscribers', $request->getUri()->getPath());

    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame('person@example.com', $body['email']);
    $this->assertSame(['456'], $body['groups']);
    $this->assertSame('Person Name', $body['fields']['name']);
  }

  /**
   * A 429 response is a retryable, transient failure.
   */
  public function testSubscribeMapsRateLimitToTransient(): void {
    $provider = $this->createProvider([new Response(429, [], '')]);

    $this->expectException(MailingListTransientException::class);
    $provider->subscribe('person@example.com', '456', []);
  }

  /**
   * A 5xx response is a retryable, transient failure.
   */
  public function testSubscribeMapsServerErrorToTransient(): void {
    $provider = $this->createProvider([new Response(500, [], '')]);

    $this->expectException(MailingListTransientException::class);
    $provider->subscribe('person@example.com', '456', []);
  }

  /**
   * A non-rate-limit 4xx response is a non-retryable, permanent failure.
   */
  public function testSubscribeMapsClientErrorToPermanent(): void {
    $provider = $this->createProvider([new Response(422, [], '')]);

    $this->expectException(MailingListPermanentException::class);
    $provider->subscribe('person@example.com', '456', []);
  }

  /**
   * A network-level connection failure is a retryable, transient failure.
   */
  public function testSubscribeMapsConnectionFailureToTransient(): void {
    $request = new Request('POST', 'https://connect.mailerlite.com/api/subscribers');
    $provider = $this->createProvider([new ConnectException('Could not connect', $request)]);

    $this->expectException(MailingListTransientException::class);
    $provider->subscribe('person@example.com', '456', []);
  }

  /**
   * Builds a provider backed by a mocked Guzzle client, config, and key.
   *
   * When $apiKey is non-empty, config resolves to a fixed key ID, and the
   * mocked key repository resolves that ID to a Key whose value is $apiKey
   * — modeling the real two-step (config -> Key ID -> Key::getKeyValue())
   * lookup. An empty $apiKey instead makes config report no key ID
   * configured at all, so request() short-circuits before ever consulting
   * the key repository.
   */
  protected function createProvider(array $responses, string $apiKey = 'test-key'): MailerliteProvider {
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    if ($apiKey !== '') {
      $key = $this->createMock(Key::class);
      $key->method('getKeyValue')->willReturn($apiKey);
      $keyRepository->method('getKey')->with('mailerlite_api_key')->willReturn($key);
    }

    return $this->buildProvider($responses, $apiKey !== '' ? 'mailerlite_api_key' : '', $keyRepository);
  }

  /**
   * Builds a provider whose configured key ID resolves to no Key entity.
   */
  protected function createProviderWithMissingKey(): MailerliteProvider {
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->with('mailerlite_api_key')->willReturn(NULL);

    return $this->buildProvider([], 'mailerlite_api_key', $keyRepository);
  }

  /**
   * Builds a provider whose Key entity throws when resolving its value.
   */
  protected function createProviderWithFailingKey(): MailerliteProvider {
    $key = $this->createMock(Key::class);
    $key->method('getKeyValue')->willThrowException(new \RuntimeException('Key provider failure.'));

    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->with('mailerlite_api_key')->willReturn($key);

    return $this->buildProvider([], 'mailerlite_api_key', $keyRepository);
  }

  /**
   * Assembles a MailerliteProvider from the given mocked collaborators.
   */
  protected function buildProvider(array $responses, string $keyId, KeyRepositoryInterface $keyRepository): MailerliteProvider {
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $this->history = [];
    $stack->push(Middleware::history($this->history));
    $client = new Client(['handler' => $stack]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('api_key')->willReturn($keyId);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('conreg_mailerlite.settings')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);

    return new MailerliteProvider([], 'mailerlite', ['label' => 'MailerLite'], $logger, $client, $configFactory, $keyRepository);
  }

}
