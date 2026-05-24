<?php

namespace Drupal\conreg\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Storage class for conreg_upgrades.
 */
class UpgradeStorage {

  public function __construct(
    protected Connection $connection,
    protected MessengerInterface $messenger,
  ) {}

  /**
   * Save an entry in the database.
   *
   * The underlying DBTNG function is $this->connection->insert().
   *
   * Exception handling is shown in this example. It could be simplified
   * without the try/catch blocks, but since an insert will throw an exception
   * and terminate your application if the exception is not handled, it is best
   * to employ try/catch.
   *
   * @param array $entry
   *   An array containing all the fields of the database record.
   *
   * @return int
   *   The number of updated rows.
   *
   * @throws \Exception
   *   When the database insert fails.
   *
   * @see $this->connection->insert()
   */
  public function insert(array $entry): int|NULL {
    $return_value = NULL;
    try {
      $return_value = $this->connection->insert('conreg_upgrades')
        ->fields($entry)
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addMessage(t('$this->connection->insert failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]), 'error');
    }
    return $return_value;
  }

  /**
   * Update an entry in the database.
   *
   * @param array $entry
   *   An array containing all the fields of the item to be updated.
   *
   * @return int
   *   The number of updated rows.
   *
   * @see $this->connection->update()
   */
  public function update(array $entry): int|NULL {
    try {
      $count = $this->connection->update('conreg_upgrades')
        ->fields($entry)
        ->condition('upgid', $entry['upgid'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addMessage(t('$this->connection->update failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]), 'error');
    }
    return $count;
  }

  /**
   * Update an entry in the database, using the lead_mid as key.
   *
   * @param array $entry
   *   An array containing all the fields of the item to be updated.
   *
   * @return int
   *   The number of updated rows.
   *
   * @see $this->connection->update()
   */
  public function updateByLeadMid(array $entry): int|NULL {
    try {
      $count = $this->connection->update('conreg_upgrades')
        ->fields($entry)
        ->condition('lead_mid', $entry['lead_mid'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addMessage(t('$this->connection->update failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]), 'error');
    }
    return $count;
  }

  /**
   * Delete an entry from the database.
   *
   * @param array $entry
   *   An array containing at least the person identifier 'pid' element of the
   *   entry to delete.
   *
   * @see $this->connection->delete()
   */
  public function delete(array $entry): void {
    $this->connection->delete('conreg_upgrades')
      ->condition('upgid', $entry['upgid'])
      ->execute();
  }

  /**
   * Delete unpaid upgrades for member.
   *
   * @param int $mid
   *   The member ID.
   */
  public function deleteUnpaidByMid($mid): void {
    $this->connection->delete('conreg_upgrades')
      ->condition('mid', $mid)
      ->condition('is_paid', 0)
      ->execute();
  }

  /**
   * Delete unpaid upgrades for any members registered by lead member.
   *
   * @param int $lead_mid
   *   The lead member ID.
   */
  public function deleteUnpaidByLeadMid($lead_mid): void {
    $this->connection->delete('conreg_upgrades')
      ->condition('lead_mid', $lead_mid)
      ->condition('is_paid', 0)
      ->execute();
  }

  /**
   * Read from the database using a filter array.
   *
   * @param array $entry
   *   Array of fields to filter on.
   *
   * @return array|bool
   *   Values read from conreg_upgrades or false if no result.
   */
  public function load(array $entry = []): array|bool {
    $select = $this->connection->select('conreg_upgrades', 'upgrades');
    $select->fields('upgrades');

    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }
    return $select->execute()->fetchAssoc();
  }

  /**
   * Read from the database and return multiple rows using a filter array.
   *
   * @param array $entry
   *   Array of fields to filter on.
   *
   * @return array
   *   Associative array of fields.
   */
  public function loadAll(array $entry = []): array {
    $select = $this->connection->select('conreg_upgrades', 'upgrades');
    $select->fields('upgrades');

    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }
    $entries = $select->execute()->fetchAll(\PDO::FETCH_ASSOC);

    return $entries;
  }

}
