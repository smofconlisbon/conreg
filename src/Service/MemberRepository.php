<?php

namespace Drupal\conreg\Service;

use Drupal\conreg\Member;

/**
 * ConReg member repository functionality.
 */
class MemberRepository {

  /**
   * Check if a member exists with specified email address.
   */
  public function emailExists(int $eid, string $email): bool {

    // Your matching logic here.
    $member = Member::loadMemberByEmail($eid, $email);
    if ($member && property_exists($member, 'email')) {
      return TRUE;
    }

    return FALSE;
  }

}
