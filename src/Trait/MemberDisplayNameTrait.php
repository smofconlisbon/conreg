<?php

declare(strict_types=1);

namespace Drupal\conreg\Trait;

/**
 * Formats a member's display name, preferring their badge name.
 *
 * Falls back to "first name last name" only when badge_name is truly
 * empty - not merely falsy. The Elvis operator (`$member['badge_name']
 * ?: ...`) this replaces would wrongly treat a badge name of literally
 * "0" as empty and fall back to the full name instead, since PHP
 * treats the string "0" as falsy.
 */
trait MemberDisplayNameTrait {

  /**
   * Returns the member's badge name, or their full name if unset.
   */
  protected function memberDisplayName(array $member): string {
    $badgeName = trim((string) ($member['badge_name'] ?? ''));
    if ($badgeName !== '') {
      return $badgeName;
    }
    return trim($member['first_name'] . ' ' . $member['last_name']);
  }

}
