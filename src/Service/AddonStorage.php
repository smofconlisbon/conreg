<?php

namespace Drupal\conreg\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Class for handling storage of add-ons.
 */
class AddonStorage {

  use StringTranslationTrait;

  /**
   * Constructs a new AddonStorage object.
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
   * Save an entry in the database.
   *
   * The underlying DBTNG function is $connection->insert().
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
   * @see $connection->insert()
   */
  public function insert($entry) {
    try {
      return $this->connection->insert('conreg_member_addons')
        ->fields($entry)
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Insert failed. Message = %message', [
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
  public function update($entry) {
    try {
      return $this->connection->update('conreg_member_addons')
        ->fields($entry)
        ->condition('addonid', $entry['addonid'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Update failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]));
      return 0;
    }
  }

  /**
   * Update member add-on by payment ID.
   */
  public function updateByPayId($entry) {
    try {
      return $this->connection->update('conreg_member_addons')
        ->fields($entry)
        ->condition('payid', $entry['payid'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Update by Pay ID failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]));
      return 0;
    }
  }

  /**
   * Delete an entry from the database.
   *
   * @param array $entry
   *   An array containing at least the person identifier 'pid' element of the
   *   entry to delete.
   *
   * @see $connection->delete()
   */
  public function delete($entry) {
    $this->connection->delete('conreg_member_addons')
      ->condition('addonid', $entry['addonid'])
      ->execute();
  }

  /**
   * Read from the database using a filter array.
   */
  public function load(array $entry = []) {
    // Read all fields from the conreg_addons table.
    $select = $this->connection->select('conreg_member_addons', 'addons');
    $select->fields('addons');

    // Add each field and value as a condition to this query.
    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }
    return $select->execute()->fetchAssoc();
  }

  /**
   * Read from the database and return multiple rows using a filter array.
   */
  public function loadAll(array $entry = []) {
    // Read all fields from the conreg_addons table.
    $select = $this->connection->select('conreg_member_addons', 'addons');
    $select->fields('addons');

    // Add each field and value as a condition to this query.
    foreach ($entry as $field => $value) {
      $select->condition($field, $value);
    }

    // Return the result in associative array format.
    return $select->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Load add-on data for report.
   */
  public function loadAddOnReport($eid, $addOn) {
    $select = $this->connection->select('conreg_members', 'm');
    $select->join('conreg_member_addons', 'a', 'm.mid = a.mid');
    $select->addField('m', 'member_no');
    $select->addField('m', 'first_name');
    $select->addField('m', 'last_name');
    $select->addField('m', 'email');
    $select->addField('a', 'addon_name');
    $select->addField('a', 'addon_option');
    $select->addField('a', 'addon_info');
    $select->addField('a', 'addon_amount');
    $select->addField('a', 'payment_ref');
    $select->condition('m.eid', $eid);
    $select->condition('m.is_paid', 1);
    // Only include members who aren't deleted and have paid.
    $select->condition("m.is_deleted", FALSE);
    $select->condition('a.is_paid', 1);

    if (!empty($addOn)) {
      $select->condition('a.addon_name', $addOn);
    }

    return $select->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

}
