<?php

namespace Drupal\conreg;

/**
 * Class for managing member upgrades.
 */
class UpgradeManager {

  /**
   * Lead member ID for this set of upgrades.
   *
   * @var int|null
   */
  public $leadMid;

  /**
   * Array of Upgrade objects for this lead member.
   *
   * @var \Drupal\conreg\Upgrade[]
   */
  public $upgrades;

  /**
   * Construct upgrade manager. Store event ID and set up array.
   *
   * @param int $eid
   *   Event ID, defaults to 1.
   */
  public function __construct(public $eid = 1) {
    $this->upgrades = [];
  }

  /**
   * Add a new upgrade to upgrade manager.
   *
   * @param \Drupal\conreg\Upgrade $upgrade
   *   Upgrade object to add.
   */
  public function add(Upgrade $upgrade) {
    if (empty($this->leadMid)) {
      $this->leadMid = $upgrade->leadMid;
    }
    else {
      $upgrade->leadMid = $this->leadMid;
    }
    $this->upgrades[] = $upgrade;
  }

  /**
   * Count the number of upgrades.
   *
   * @return int
   *   Number of upgrades.
   */
  public function count() {
    return count($this->upgrades);
  }

  /**
   * Loop through all upgrades, sum total price, and return.
   *
   * @return float
   *   Total upgrade price.
   */
  public function getTotalPrice() {
    $total = 0;
    foreach ($this->upgrades as $upgrade) {
      $total = +$upgrade->upgradePrice;
    }
    return $total;
  }

  /**
   * Save all upgrades to conreg_upgrades table.
   *
   * @return int|null
   *   Lead member ID.
   */
  public function saveUpgrades() {
    UpgradeStorage::deleteUnpaidByLeadMid($this->leadMid);

    $total = $this->getTotalPrice();
    // Create array containing member IDs of members to upgrade.
    foreach ($this->upgrades as $upgrade) {
      $upgrade->saveUpgrade($total);
    }
    return $this->leadMid;
  }

  /**
   * Load upgrades for a member.
   *
   * @param int $mid
   *   Member ID.
   * @param int $isPaid
   *   Filter for paid/unpaid upgrades.
   *
   * @return bool
   *   TRUE if upgrades loaded, FALSE otherwise.
   */
  public function loadUpgrades($mid, $isPaid) {
    $this->upgrades = [];
    $upgrades = UpgradeStorage::loadAll(['lead_mid' => $mid, 'is_paid' => $isPaid]);
    if (empty($upgrades)) {
      $upgrades = UpgradeStorage::loadAll(['mid' => $mid, 'is_paid' => $isPaid]);
    }
    if (!empty($upgrades)) {
      $this->leadMid = $upgrades['lead_mid'];
      foreach ($upgrades as $upgrade) {
        $this->add(new Upgrade(
          $this->eid,
          $upgrade['mid'],
          NULL,
          $this->leadMid,
          $upgrade['from_type'],
          $upgrade['from_days'],
          $upgrade['to_type'],
          $upgrade['to_days'],
          $upgrade['to_badge_type'],
          $upgrade['upgrade_price'],
        ));
      }
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Complete all upgrades after payment.
   *
   * @param float $payment_amount
   *   Payment amount received.
   * @param string $payment_method
   *   Payment method.
   * @param string $payment_id
   *   Payment reference ID.
   */
  public function completeUpgrades($payment_amount, $payment_method, $payment_id) {
    foreach ($this->upgrades as $upgrade) {
      $upgrade->complete($this->leadMid, $payment_amount, $payment_method, $payment_id);
    }
  }

}
