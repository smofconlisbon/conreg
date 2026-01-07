<?php

namespace Drupal\conreg;

/**
 * Class for managing member upgrades.
 */
class UpgradeManager {
  public $leadMid;
  public $upgrades;

  /**
   * Construct upgrade manager. Store event ID and set up array.
   */
  public function __construct(public $eid = 1) {
    $this->upgrades = [];
  }

  /**
   * Add a new upgrade to upgrade manager.
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
   * Return count of available upgrades.
   */
  public function count() {
    return count($this->upgrades);
  }

  /**
   * Loop through all members, add up total price, and return to caller.
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
   * Load upgrades for member.
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
   * Complete paid for upgrades.
   */
  public function completeUpgrades($payment_amount, $payment_method, $payment_id) {
    foreach ($this->upgrades as $upgrade) {
      $upgrade->complete($this->leadMid, $payment_amount, $payment_method, $payment_id);
    }
  }

}
