<?php

namespace Drupal\conreg_clickup\Hook;

use Drupal\conreg_clickup\ConregClickUp;
use Drupal\conreg\Member;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for conreg_clickup.
 */
class ConregClickupHooks {

  /**
   * Implements hook_convention_member_updated().
   */
  #[Hook('convention_member_updated')]
  public static function conventionMemberUpdated(Member $member) {
    // Create ClickUp tasks for options.
    ConregClickUp::createMemberTasks($member->eid, $member->mid, $member->getOptions());
  }

}
