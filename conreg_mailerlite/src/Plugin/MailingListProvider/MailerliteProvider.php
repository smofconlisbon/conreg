<?php

declare(strict_types=1);

namespace Drupal\conreg_mailerlite\Plugin\MailingListProvider;

use Drupal\conreg_mailing_list\Attribute\MailingListProvider;
use Drupal\conreg_mailing_list\Exception\MailingListPermanentException;
use Drupal\conreg_mailing_list\Exception\MailingListTransientException;
use Drupal\conreg_mailing_list\MailingListProviderPluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mailerlite implementation of the mailing list provider contract.
 *
 * Talks to the Mailerlite "connect" API. Credentials are stored globally at
 * conreg_mailerlite.settings, shared by all events.
 */
#[MailingListProvider(
  id: 'mailerlite',
  label: new TranslatableMarkup('MailerLite'),
  description: new TranslatableMarkup('Allows ConReg members to be subscribed to MailerLite mailing lists.'),
)]
final class MailerliteProvider extends MailingListProviderPluginBase {

  const BASE_URL = 'https://connect.mailerlite.com/api';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    #[Autowire(service: 'logger.channel.conreg_mailing_list')]
    LoggerInterface $logger,
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'key.repository')]
    protected KeyRepositoryInterface $keyRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public function getLists(): array {
    $data = $this->request('GET', '/groups');
    $lists = [];
    foreach ($data['data'] ?? [] as $group) {
      if (isset($group['id'])) {
        $lists[(string) $group['id']] = $group['name'] ?? $group['id'];
      }
    }
    return $lists;
  }

  /**
   * {@inheritdoc}
   */
  public function subscribe(string $email, string $listId, array $fields): void {
    // Upsert by email and assign to the group. Mailerlite will not resubscribe
    // an address the account records as unsubscribed.
    $body = [
      'email' => $email,
      'groups' => [$listId],
    ];
    if (!empty($fields['name'])) {
      $body['fields'] = ['name' => $fields['name']];
    }
    $this->request('POST', '/subscribers', $body);
  }

  /**
   * Perform an API request, mapping failures to the provider exceptions.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   API path relative to the base URL.
   * @param array|null $body
   *   Optional JSON body.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Drupal\conreg_mailing_list\Exception\MailingListTransientException
   * @throws \Drupal\conreg_mailing_list\Exception\MailingListPermanentException
   */
  protected function request(string $method, string $path, ?array $body = NULL): array {
    $keyId = $this->configFactory->get('conreg_mailerlite.settings')->get('api_key');
    if (empty($keyId)) {
      // No credentials configured: retryable, since an admin may add them.
      throw new MailingListTransientException('Mailerlite API key is not configured.');
    }

    try {
      $apiKey = $this->keyRepository->getKey($keyId)?->getKeyValue();
    }
    catch (\Throwable $e) {
      // A key provider (env var, file, etc.) can fail to resolve a value;
      // treat the same as "not configured yet" — retryable, an admin may
      // fix it.
      throw new MailingListTransientException('Unable to retrieve the Mailerlite API key: ' . $e->getMessage(), 0, $e);
    }
    if (empty($apiKey)) {
      throw new MailingListTransientException('Mailerlite API key is not configured.');
    }

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Accept' => 'application/json',
      ],
      'http_errors' => TRUE,
    ];
    if ($body !== NULL) {
      $options['json'] = $body;
    }

    try {
      $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);
      $contents = (string) $response->getBody();
      return $contents === '' ? [] : (json_decode($contents, TRUE) ?: []);
    }
    catch (ConnectException $e) {
      // Network level failure: always retryable.
      throw new MailingListTransientException($e->getMessage(), 0, $e);
    }
    catch (RequestException $e) {
      $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      // Rate limiting and server errors are retryable; other 4xx are not.
      if ($status === 429 || $status >= 500 || $status === 0) {
        throw new MailingListTransientException($e->getMessage(), 0, $e);
      }
      throw new MailingListPermanentException($e->getMessage(), 0, $e);
    }
  }

}
