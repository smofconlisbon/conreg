<?php

declare(strict_types=1);

namespace Drupal\conreg\Trait;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Verifies a loaded member actually belongs to the event named in the route.
 *
 * Several Member Check-In actions (Badge name, Preview label, Undo
 * check-in, Reprint label) take {eid}/{mid} from the route but load the
 * member by $mid alone, then use $eid - not the member's own eid column
 * - for event-scoped lookups (member classes, printers, and so on). The
 * main check-in table itself can never link to a mismatched combination
 * (its search results are already scoped to the route's event), but
 * these routes are reachable directly, so a hand-typed or stale URL
 * naming one event with a member ID that actually belongs to a
 * different one would otherwise silently apply the wrong event's
 * config to that member's data.
 */
trait AssertMemberEventTrait {

  /**
   * Throws a 404 if the loaded member doesn't belong to $eid.
   *
   * @param array $member
   *   A member record as returned by MemberStorage::load().
   * @param int $eid
   *   The event ID taken from the route.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the member belongs to a different event.
   */
  protected function assertMemberBelongsToEvent(array $member, int $eid): void {
    if ((int) $member['eid'] !== $eid) {
      throw new NotFoundHttpException('Member does not belong to this event.');
    }
  }

}
