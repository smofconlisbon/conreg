<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list\Controller;

use Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface;
use Drupal\conreg_mailing_list\MailingListSyncService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Backfills existing members against a subscription rule on demand.
 */
final class SyncSubscriptionRuleController extends ControllerBase {

  public function __construct(
    protected MailingListSyncService $syncService,
  ) {}

  /**
   * Enqueues matching members and redirects back to the rule collection.
   */
  public function sync(ConregSubscriptionRuleInterface $conreg_subscription_rule): RedirectResponse {
    $count = $this->syncService->syncRule($conreg_subscription_rule);
    $this->messenger()->addStatus($this->formatPlural(
      $count,
      '1 matching member enqueued for subscription.',
      '@count matching members enqueued for subscription.',
    ));

    return $this->redirect('entity.conreg_subscription_rule.collection', [
      'eid' => $conreg_subscription_rule->getEventId(),
    ]);
  }

}
