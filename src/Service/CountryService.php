<?php

namespace Drupal\conreg\Service;

use Drupal\Component\Serialization\Json;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Country service for Simple Convention Registration.
 */
class CountryService implements CountryServiceInterface {

  /**
   * Constructs a new CountryService.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(
    protected RequestStack $request_stack,
    protected ClientInterface $http_client,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getUserCountry(): string {
    // URL for IP country service.
    $url = 'http://ip-api.com/json/';

    try {
      // Get IP address from request.
      $request = $this->request_stack->getCurrentRequest();
      if (!$request) {
        return '';
      }
      $ip = $request->getClientIp();
      $response = $this->http_client->request('GET', $url . $ip)->getBody()->getContents();
      $decoded = Json::decode($response);
      if (!isset($decoded['status']) || $decoded['status'] != 'success') {
        return '';
      }
    }
    catch (GuzzleException $e) {
      return '';
    }

    return $decoded['countryCode'] ?? '';
  }

}
