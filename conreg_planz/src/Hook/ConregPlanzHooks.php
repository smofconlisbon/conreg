<?php

namespace Drupal\conreg_planz\Hook;

use Drupal\conreg\Member;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for conreg_planz.
 */
class ConregPlanzHooks {

  /**
   * Implements hook_convention_member_inserted().
   */
  #[Hook('convention_member_inserted')]
  public static function conventionMemberInserted(Member $member): void {
    _conreg_planz_check_user($member);
  }

  /**
   * Implements hook_convention_member_updated().
   */
  #[Hook('convention_member_updated')]
  public static function conventionMemberUpdated(Member $member): void {
    _conreg_planz_check_user($member);
  }

}
