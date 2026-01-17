<?php

namespace Drupal\conreg_discord;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Utility\Error;
use GuzzleHttp\Exception\RequestException;

/**
 * Class to handle communication with Discord.
 */
class Discord {

  /**
   * Discord API base URL.
   */
  const BASE_URL = 'https://discordapp.com/api/';

  /**
   * Discord invite base URL.
   */
  const INVITE_URL = 'https://discordapp.com/invite/';

  /**
   * Discord bot token.
   *
   * @var string
   */
  public $token;

  /**
   * Discord channel ID.
   *
   * @var string
   */
  public $channelId;

  /**
   * Discord channel object.
   *
   * @var object|null
   */
  public $channel;

  /**
   * Generated invite code.
   *
   * @var string|null
   */
  public $inviteCode;

  /**
   * Status or error message.
   *
   * @var string
   */
  public $message;

  /**
   * Constructs a new Discord object.
   *
   * @param string $token
   *   The Discord bot token.
   * @param string $channelId
   *   The Discord channel ID.
   */
  public function __construct($token, $channelId) {
    $this->token = $token;
    $this->channelId = $channelId;
  }

  /**
   * Gets the channel object.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function getChannel() {
    $client = \Drupal::httpClient();
    try {
      $response = $client->get(self::BASE_URL . '/channels/' . $this->channelId, [
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bot ' . $this->token,
        ],
      ])->getBody()->getContents();
      $this->channel = (object) Json::decode($response);
      $this->message = '';
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $response_info = Json::decode($response->getBody()->getContents());
      $logger = \Drupal::logger('modulename');
      Error::logException($logger, $e, 'Failed to get channel information with error: @error (@code).', [
        '@error' => $response_info['channel_id'][0],
        '@code' => $response->getStatusCode(),
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Gets an invite to the channel.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function getChannelInvite() {
    $client = \Drupal::httpClient();
    $json = [
      'max_age' => 0,
      'max_uses' => 1,
      'unique' => TRUE,
    ];
    try {
      $response = $client->post(self::BASE_URL . '/channels/' . $this->channelId . '/invites', [
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bot ' . $this->token,
        ],
        'body' => json_encode($json, JSON_FORCE_OBJECT),
      ])->getBody()->getContents();
      $decoded = (object) Json::decode($response);
      $this->inviteCode = $decoded->code;
      $this->message = '';
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $response_info = Json::decode($response->getBody()->getContents());
      $logger = \Drupal::logger('modulename');
      Error::logException($logger, $e, 'Failed to create invite code with error: @error (@code).', [
        '@error' => $response_info['channel_id'][0],
        '@code' => $response->getStatusCode(),
      ]);
      return FALSE;
    }

    return TRUE;
  }

}
