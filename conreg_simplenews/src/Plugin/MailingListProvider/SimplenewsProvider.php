<?php

declare(strict_types=1);

namespace Drupal\conreg_simplenews\Plugin\MailingListProvider;

use Drupal\conreg_mailing_list\Attribute\MailingListProvider;
use Drupal\conreg_mailing_list\Exception\MailingListTransientException;
use Drupal\conreg_mailing_list\MailingListProviderPluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simplenews\Subscription\SubscriptionManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Simplenews implementation of the mailing list provider contract.
 */
#[MailingListProvider(
  id: 'simplenews',
  label: new TranslatableMarkup('Simplenews'),
  description: new TranslatableMarkup('Allows ConReg members to be subscribed to Simplenews newsletters.'),
)]
final class SimplenewsProvider extends MailingListProviderPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    #[Autowire(service: 'logger.channel.conreg_mailing_list')]
    LoggerInterface $logger,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'simplenews.subscription_manager')]
    protected SubscriptionManagerInterface $subscriptionManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public function getLists(): array {
    $storage = $this->entityTypeManager->getStorage('simplenews_newsletter');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('weight')->execute();
    $lists = [];
    foreach ($storage->loadMultiple($ids) as $newsletter) {
      $lists[$newsletter->id()] = $newsletter->label();
    }
    return $lists;
  }

  /**
   * {@inheritdoc}
   */
  public function subscribe(string $email, string $listId, array $fields): void {
    try {
      $this->subscriptionManager->subscribe($email, $listId);
    }
    catch (\Exception $e) {
      // Local entity-save failures have no natural transient/permanent split
      // the way HTTP status codes do; treat as transient since a queue retry
      // is harmless (subscribe() is idempotent by email).
      throw new MailingListTransientException($e->getMessage(), 0, $e);
    }
  }

}
