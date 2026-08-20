<?php

namespace Drupal\conreg_airtable\Hook;

use Drupal\conreg_airtable\AirTable;
use Drupal\conreg\Member;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for conreg_airtable.
 */
class ConregAirtableHooks {

  /**
   * Implements hook_convention_member_added().
   */
  #[Hook('convention_member_added')]
  public static function conventionMemberAdded(Member $member) {
    AirTable::addMembers($member->eid, [
      $member->mid,
    ]);
  }

  /**
   * Implements hook_convention_member_updated().
   */
  #[Hook('convention_member_updated')]
  public static function conventionMemberUpdated(Member $member) {
    $connection = \Drupal::database();
    $query = $connection->select('conreg_airtable_members', 'a');
    $query->addField('a', 'airtable_id');
    $query->condition('a.mid', $member->mid);
    $airtable_id = $query->execute()->fetchField();
    if (empty($airtable_id)) {
      AirTable::addMembers($member->eid, [
        $member->mid,
      ]);
    }
    else {
      AirTable::updateMembers($member->eid, [
        $member->mid => $airtable_id,
      ]);
    }
  }

  /**
   * Implements hook_convention_member_deleted().
   */
  #[Hook('convention_member_deleted')]
  public static function conventionMemberDeleted(Member $member) {
    $connection = \Drupal::database();
    $query = $connection->select('conreg_airtable_members', 'a');
    $query->addField('a', 'airtable_id');
    $query->condition('a.mid', $member->mid);
    $airtable_id = $query->execute()->fetchField();
    if (!empty($airtable_id)) {
      AirTable::deleteMember($member->eid, $airtable_id);
    }
  }

}
