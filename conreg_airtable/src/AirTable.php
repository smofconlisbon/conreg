<?php

namespace Drupal\conreg_airtable;

use Drupal\Component\Serialization\Json;
use Drupal\conreg\Member;
use Drupal\conreg\ConregConfig;
use Drupal\conreg\FieldOptions;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Exception\ClientException;

/**
 *
 */
class AirTable {

  /**
   *
   */
  public static function test($eid) {
    $config = ConregConfig::getConfig($eid);
    $api_url = $config->get('airtable.api_url') . '?maxRecords=3';
    $api_key = $config->get('airtable.api_key');

    try {
      $client = \Drupal::httpClient();
      $response = $client->get($api_url, [
        'verify' => TRUE,
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
      ])->getBody()->getContents();
    }
    catch (ClientException $e) {
      return FALSE;
    }

    return $response;
  }

  /**
   *
   */
  public static function addMembers($eid, $mids) {
    $config = ConregConfig::getConfig($eid);
    $fieldOptions = FieldOptions::getFieldOptions($eid);
    $records = new \stdClass();
    $records->records = [];
    foreach ($mids as $mid) {
      $records->records[] = self::getMemberFields($mid, $airtable_id, $config, $fieldOptions);
    }
    if ($return = self::postMembers($eid, $records)) {
      $records = Json::decode($return);
      $connection = \Drupal::database();
      foreach ($records['records'] as $key => $value) {
        $id = $value['id'];
        $entry = ['mid' => $mids[$key], 'airtable_id' => $id];
        $connection->insert('conreg_airtable_members')
          ->fields($entry)
          ->execute();
      }
      return $records;
    }
    return FALSE;
  }

  /**
   *
   */
  public static function updateMembers($eid, $airtable_ids) {
    $config = ConregConfig::getConfig($eid);
    $fieldOptions = FieldOptions::getFieldOptions($eid);
    $records = new \stdClass();
    $records->records = [];
    foreach ($airtable_ids as $mid => $airtable_id) {
      $records->records[] = self::getMemberFields($mid, $airtable_id, $config, $fieldOptions);
    }
    if ($return = self::putMembers($eid, $records)) {
      $records = Json::decode($return);
      return $records;
    }
  }

  /**
   *
   */
  public static function getMemberFields($mid, $airtable_id, $config, $fieldOptions) {
    $fields = new \stdClass();
    $fields->id = $airtable_id;
    $fields->fields = new \stdClass();
    $member = Member::loadMember($mid);
    foreach ($config->get('airtable.mappings') as $field => $mapped) {
      if (!empty($mapped)) {
        $fields->fields->$mapped = $member->fieldDisplay($field);
      }
    }
    foreach ($config->get('airtable.option_groups') as $groupId => $mapped) {
      if (!empty($mapped)) {
        $vals = [];
        foreach ($fieldOptions->groups[$groupId]->options as $option) {
          if (array_key_exists($option->optionId, $member->options) && $member->options[$option->optionId]->isSelected == 1) {
            $vals[] = $option->title;
          }
        }
        $fields->fields->$mapped = implode(', ', $vals);
      }
    }
    foreach ($config->get('airtable.option_fields') as $optionId => $mapped) {
      if (!empty($mapped)) {
        if (array_key_exists($optionId, $member->options) && $member->options[$optionId]->isSelected == 1) {
          $fields->fields->$mapped = '✓ ' . $member->options[$optionId]->optionDetail;
        }
      }
    }
    return $fields;
  }

  /**
   *
   */
  public static function removeMembers($eid, $airtable_ids) {
    $records = new \stdClass();
    $records->records = [];
    foreach ($airtable_ids as $airtable_id) {
      $fields = new \stdClass();
      $fields->id = $airtable_id;
      $fields->deleted = TRUE;
      $records->records[] = $fields;
    }
    if ($return = self::deleteMembers($eid, $records)) {
      $records = Json::decode($return);
      return $records;
    }
  }

  /**
   *
   */
  public static function postMembers($eid, $records) {
    $config = ConregConfig::getConfig($eid);
    $api_url = $config->get('airtable.api_url');
    $api_key = $config->get('airtable.api_key');

    try {
      $client = \Drupal::httpClient();
      $response = $client->post($api_url, [
        'verify' => TRUE,
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => Json::encode($records),
      ])->getBody()->getContents();
    }
    catch (ClientException $e) {
      // \Drupal::messenger()->addMessage(t('Error adding to AirTable: '), 'error');
      \Drupal::logger('conreg_airtable')->info('Client Exception inserting into Airtable: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
    catch (TransferException $e) {
      \Drupal::logger('conreg_airtable')->info('Transfer Exception inserting into Airtable: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }

    return $response;
  }

  /**
   *
   */
  public static function putMembers($eid, $records) {
    $config = ConregConfig::getConfig($eid);
    $api_url = $config->get('airtable.api_url');
    $api_key = $config->get('airtable.api_key');

    try {
      $client = \Drupal::httpClient();
      $response = $client->put($api_url, [
        'verify' => TRUE,
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => Json::encode($records),
      ])->getBody()->getContents();
      return $response;
    }
    catch (ClientException $e) {
      \Drupal::logger('conreg_airtable')->info('Client Exception updating entry in Airtable: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
    catch (TransferException $e) {
      \Drupal::logger('conreg_airtable')->info('Transfer Exception updating entry in Airtable: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }

  }

  /**
   *
   */
  public static function deleteMembers($eid, $records) {
    $config = ConregConfig::getConfig($eid);
    $api_url = $config->get('airtable.api_url');
    $api_key = $config->get('airtable.api_key');

    try {
      $client = \Drupal::httpClient();
      $response = $client->delete($api_url, [
        'verify' => TRUE,
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => Json::encode($records),
      ])->getBody()->getContents();
      return $response;
    }
    catch (ClientException $e) {
      \Drupal::logger('conreg_airtable')->info('Client Exception deleting from Airtable: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
    catch (TransferException $e) {
      \Drupal::logger('conreg_airtable')->info('Transfer Exception deleting from Airtable: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }

  }

}
