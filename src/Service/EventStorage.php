<?php

namespace Drupal\conreg\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Functions to help load and save events.
 */
class EventStorage {

  /**
   * Constructs an EventStorage service.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    protected Connection $connection,
    protected MessengerInterface $messenger,
  ) {}

  /**
   * Save an event in the database.
   *
   * @param array $entry
   *   An array containing all the fields of the database record.
   *
   * @return int|null
   *   The inserted record ID, or NULL on failure.
   */
  public function insert(array $entry): ?int {
    try {
      return $this->connection
        ->insert('conreg_events')
        ->fields($entry)
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError(t(
        'Insert failed. Message = %message',
        ['%message' => $e->getMessage()]
      ));
    }

    return NULL;
  }

  /**
   * Update an event in the database.
   *
   * @param array $entry
   *   An array containing all the fields of the item to be updated.
   *
   * @return int
   *   The number of updated rows.
   */
  public function update(array $entry): int {
    try {
      return $this->connection
        ->update('conreg_events')
        ->fields($entry)
        ->condition('eid', $entry['eid'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError(t(
        'Update failed. Message = %message',
        ['%message' => $e->getMessage()]
      ));
    }

    return 0;
  }

  /**
   * Delete an event from the database.
   *
   * @param array $entry
   *   An array containing at least the event identifier 'eid'.
   */
  public function delete(array $entry): void {
    $this->connection
      ->delete('conreg_events')
      ->condition('eid', $entry['eid'])
      ->execute();
  }

  /**
   * Load a single event from the database.
   *
   * @param array $entry
   *   An associative array of field/value conditions.
   *
   * @return array|false
   *   The event record as an associative array, or FALSE if not found.
   */
  public function load(array $entry = []): array|false {
    $select = $this->connection
      ->select('conreg_events', 'e')
      ->fields('e');

    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }

    return $select->execute()->fetchAssoc();
  }

  /**
   * Load multiple events from the database.
   *
   * @param array $entry
   *   An associative array of field/value conditions.
   *
   * @return array
   *   An array of event records.
   */
  public function loadAll(array $entry = []): array {
    $select = $this->connection
      ->select('conreg_events', 'e')
      ->fields('e');

    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }

    return $select->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Get a list of event options.
   *
   * @return array
   *   An associative array keyed by event ID, containing event names.
   */
  public function eventOptions(): array {
    return $this->connection
      ->select('conreg_events', 'e')
      ->fields('e', ['eid', 'event_name'])
      ->orderBy('event_name')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

}
