<?php

declare(strict_types=1);

namespace Drupal\conreg\Trait;

use Drupal\Core\Config\ImmutableConfig;

/**
 * Trait to add showBadgeNumber method.
 */
trait ShowBadgeNumberTrait {

  /**
   * Format the badge number.
   */
  protected function showBadgeNumber(array $member, ImmutableConfig $config): string {
    $digits = $config->get('member_no_digits');
    if (!$member['member_no']) {
      return '';
    }
    $badge_type = trim($member['badge_type']);
    $member_no = sprintf("%0" . $digits . "d", $member['member_no']);
    return $badge_type . $member_no;
  }

}
