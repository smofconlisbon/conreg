<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list\Hook;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\conreg\Member;
use Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface;
use Drupal\conreg_mailing_list\Plugin\QueueWorker\MailingListSubscriptionWorker;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;

/**
 * Class for mailing list hooks.
 */
class ConregMailingListHooks {

  public function __construct(
    protected QueueFactory $queueFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EmailValidatorInterface $emailValidator,
  ) {}

  /**
   * Handler for hook_convention_member_added.
   */
  #[Hook('convention_member_added')]
  public function memberAdded(Member $member) {
    $eid = $member->eid;
    $storage = $this->entityTypeManager->getStorage('conreg_subscription_rule');
    $ids = $storage
      ->getQuery()
      ->condition('eid', $eid)
      ->accessCheck(FALSE)
      ->execute();
    $entities = $storage->loadMultiple($ids);
    /** @var \Drupal\conreg_mailing_list\Entity\ConregSubscriptionRule $subscriptionRule */
    foreach ($entities as $subscriptionRule) {
      $this->enqueueIfMatches($member, $subscriptionRule);
    }
  }

  /**
   * Enqueues a member for subscription if they match the given rule.
   *
   * @param \Drupal\conreg\Member $member
   *   The member to check.
   * @param \Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface $rule
   *   The subscription rule to check the member against.
   *
   * @return bool
   *   TRUE if the member was enqueued, FALSE otherwise.
   */
  public function enqueueIfMatches(Member $member, ConregSubscriptionRuleInterface $rule): bool {
    if (!$this->emailValidator->isValid($member->email) || !$rule->matches($member)) {
      return FALSE;
    }

    // Get the queue and add item containing member and subscription rule.
    $queue = $this->queueFactory->get(MailingListSubscriptionWorker::QUEUE_NAME);
    $item = new \stdClass();
    $item->member = $member;
    $item->rule = $rule;
    $queue->createItem($item);

    return TRUE;
  }

}
