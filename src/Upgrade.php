<?php

namespace Drupal\conreg;

/**
 * Details of member upgrade.
 */
class Upgrade {

  /**
   * The event ID.
   */
  public $leadMid;
  public $fromType;
  public $fromDays;
  public $toType;
  public $toDays;
  public $toBadgeType;
  public $upgradePrice;

  /**
   * Construct the upgrade object.
   */
  public function __construct(
    public $eid = 1,
    public $mid = NULL,
    $upgid = NULL,
    &$lead_mid = NULL,
    $fromType = NULL,
    $fromDays = NULL,
    $toType = NULL,
    $toDays = NULL,
    $toBadgeType = NULL,
    $upgradePrice = NULL,
  ) {
    $this->leadMid = $lead_mid;

    // Ensure that Lead MID is passed back to caller.
    $lead_mid = $this->getLead();

    if (!empty($upgid)) {
      $this->setUpgrade($upgid);
    }
    else {
      // Only use passed in values for.
      $this->fromType = $fromType;
      $this->fromDays = $fromDays;
      $this->toType = $toType;
      $this->toDays = $toDays;
      $this->toBadgeType = $toBadgeType;
      $this->upgradePrice = $upgradePrice;
    }
  }

  /**
   * Get the lead member.
   */
  public function getLead() {
    if (empty($this->leadMid) && !empty($this->mid)) {
      if ($member = ConregStorage::load(['mid' => $this->mid])) {
        $this->leadMid = $member['lead_mid'];
      }
    }
    return $this->leadMid;
  }

  /**
   * Get upgrade type details from config.
   */
  public function setUpgrade($upgid) {
    $upgrades = ConregOptions::memberUpgrades($this->eid);
    if (isset($upgrades->upgrades[$upgid])) {
      $this->fromType = $upgrades->upgrades[$upgid]->fromType;
      $this->fromDays = $upgrades->upgrades[$upgid]->fromDays;
      $this->toType = $upgrades->upgrades[$upgid]->toType;
      $this->toDays = $upgrades->upgrades[$upgid]->toDays;
      $this->toBadgeType = $upgrades->upgrades[$upgid]->toBadgeType;
      $this->upgradePrice = $upgrades->upgrades[$upgid]->price;
    }
  }

  /**
   * Save the upgrade record.
   */
  public function saveUpgrade($upgradeTotal) {
    UpgradeStorage::deleteUnpaidByMid($this->mid);

    UpgradeStorage::insert([
      'mid' => $this->mid,
      'eid' => $this->eid,
      'lead_mid' => $this->leadMid,
      'from_type' => $this->fromType,
      'from_days' => $this->fromDays,
      'to_type' => $this->toType,
      'to_days' => $this->toDays,
      'to_badge_type' => $this->toBadgeType,
      'upgrade_price' => $this->upgradePrice,
      'is_paid' => 0,
      'payment_amount' => $upgradeTotal,
      'upgrade_date' => time(),
    ]);
  }

  /**
   * Function to complete upgrade when payment received.
   */
  public function complete($lead_mid, $payment_amount, $payment_method, $payment_id) {
    $upgrade = UpgradeStorage::load([
      'eid' => $this->eid,
      'mid' => $this->mid,
      'is_paid' => 0,
    ]);
    // Update upgrade record.
    $update = [
      'upgid' => $upgrade['upgid'],
      'lead_mid' => $lead_mid,
      'payment_amount' => $payment_amount,
      'payment_method' => $payment_method,
      'payment_id' => $payment_id,
      'is_paid' => 1,
      'upgrade_date' => \Drupal::time()->getRequestTime(),
    ];
    UpgradeStorage::update($update);
    // Fetch member record.
    if ($member = ConregStorage::load(['mid' => $this->mid])) {
      // Update member type, days and price.
      $member['member_type'] = $this->toType;
      $member['days'] = $this->toDays;
      $member['badge_type'] = $this->toBadgeType;
      $member['member_price'] += $this->upgradePrice;
      $member['member_total'] = $member['member_price'] + $member['add_on_price'] + $this->upgradePrice;
      $member['update_date'] = time();
      // Save updated member.
      ConregStorage::update($member);
    }
  }

}
