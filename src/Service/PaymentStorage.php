<?php

namespace Drupal\conreg\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Storage service for payments and payment lines.
 */
class PaymentStorage {

  use StringTranslationTrait;

  /**
   * Constructs a PaymentStorage object.
   */
  public function __construct(
    protected Connection $connection,
    protected MessengerInterface $messenger,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Save an entry in the database.
   *
   * @param array $entry
   *   An array containing all the fields of the database record.
   *
   * @return int|null
   *   The inserted payment ID, or NULL if the insert failed.
   */
  public function insert(array $entry): int|NULL {
    try {
      return $this->connection->insert('conreg_payments')
        ->fields($entry)
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Payment insert failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]));
      return NULL;
    }
  }

  /**
   * Insert payment line.
   *
   * @param array $entry
   *   An array containing all the fields of the payment line.
   *
   * @return int|null
   *   The inserted payment line ID, or NULL if the insert failed.
   */
  public function insertLine(array $entry): int|NULL {
    try {
      return $this->connection->insert('conreg_payment_lines')
        ->fields($entry)
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Payment line insert failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]));
      return NULL;
    }
  }

  /**
   * Update an entry in the database.
   *
   * @param array $entry
   *   An array containing all the fields of the item to be updated.
   *
   * @return int
   *   The number of updated rows.
   */
  public function update(array $entry): int {
    try {
      return $this->connection->update('conreg_payments')
        ->fields($entry)
        ->condition('payid', $entry['payid'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Payment update failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]));
      return 0;
    }
  }

  /**
   * Update payment line record.
   *
   * @param array $entry
   *   An array containing all the fields of the payment line to update.
   *
   * @return int
   *   The number of updated rows.
   */
  public function updateLine(array $entry): int {
    try {
      return $this->connection->update('conreg_payment_lines')
        ->fields($entry)
        ->condition('lineid', $entry['lineid'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Payment line update failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]));
      return 0;
    }
  }

  /**
   * Delete an entry from the database.
   *
   * @param array $entry
   *   An array containing the payment identifier.
   */
  public function delete(array $entry): void {
    $this->connection->delete('conreg_payments')
      ->condition('payid', $entry['payid'])
      ->execute();
  }

  /**
   * Delete payment lines.
   *
   * @param array $entry
   *   An array containing the payment line identifier.
   */
  public function deleteLine(array $entry): void {
    $this->connection->delete('conreg_payment_lines')
      ->condition('lineid', $entry['lineid'])
      ->execute();
  }

  /**
   * Read from the database using a filter array.
   *
   * @param array $entry
   *   Array of fields to filter on.
   *
   * @return array|bool
   *   The matching payment, or FALSE if no payment was found.
   */
  public function load(array $entry = []): array|bool {
    $select = $this->connection->select('conreg_payments', 'payments');
    $select->fields('payments');

    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }
    return $select->execute()->fetchAssoc();
  }

  /**
   * Load payment line matching condition.
   *
   * @param array $entry
   *   Array of fields to filter on.
   *
   * @return array|bool
   *   The matching payment line, or FALSE if none was found.
   */
  public function loadLine(array $entry = []): array|bool {
    $select = $this->connection->select('conreg_payment_lines', 'payments');
    $select->fields('payments');

    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }
    return $select->execute()->fetchAssoc();
  }

  /**
   * Read multiple payments using a filter array.
   *
   * @param array $entry
   *   Array of fields to filter on.
   *
   * @return array
   *   The matching payments.
   */
  public function loadAll(array $entry = []): array {
    $select = $this->connection->select('conreg_payments', 'payments');
    $select->fields('payments');

    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }
    return $select->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Load all lines matching criteria.
   *
   * @param array $entry
   *   Array of fields to filter on.
   *
   * @return array
   *   The matching payment lines.
   */
  public function loadAllLines(array $entry = []): array {
    $select = $this->connection->select('conreg_payment_lines', 'payments');
    $select->fields('payments');

    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }
    return $select->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Check if valid payment ID and key combination.
   */
  public function checkPaymentKey(int $payid, int $key): bool {
    $select = $this->connection->select('conreg_payments', 'payments');
    $select->fields('payments');
    $select->condition('payid', $payid);
    $select->condition('random_key', $key);

    return $select->countQuery()->execute()->fetchField() > 0;
  }

}
