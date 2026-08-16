<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list;

use Drupal\conreg\Member;
use Drupal\conreg\MemberOption;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg_mailing_list\Hook\ConregMailingListHooks;

/**
 * Backfills existing members against a subscription rule.
 */
final class MailingListSyncService {

  public function __construct(
    protected MemberStorage $memberStorage,
    protected ConregMailingListHooks $hooks,
  ) {}

  /**
   * Enqueues every member of the rule's event who currently matches it.
   *
   * Subscribe-only: members already subscribed via a matching rule are
   * simply re-enqueued (the provider's subscribe() is documented as an
   * upsert), and members who no longer match a rule are never
   * unsubscribed here — nothing tracks which rule is responsible for
   * which subscription, so there's no safe way to know who to remove.
   *
   * @param \Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface $rule
   *   The subscription rule to sync.
   *
   * @return int
   *   The number of members enqueued.
   */
  public function syncRule(ConregSubscriptionRuleInterface $rule): int {
    $count = 0;
    foreach ($this->memberStorage->loadAll(['eid' => $rule->getEventId()]) as $row) {
      $member = Member::newMember($row);
      $member->options = MemberOption::loadAllMemberOptions($member->mid);
      if ($this->hooks->enqueueIfMatches($member, $rule)) {
        $count++;
      }
    }
    return $count;
  }

}
