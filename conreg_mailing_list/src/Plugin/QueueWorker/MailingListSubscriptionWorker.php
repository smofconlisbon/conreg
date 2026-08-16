<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list\Plugin\QueueWorker;

use Drupal\conreg_mailing_list\Exception\MailingListPermanentException;
use Drupal\conreg_mailing_list\Exception\MailingListTransientException;
use Drupal\conreg_mailing_list\MailingListProviderPluginManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Defines 'conreg_mailing_list_subscription' queue worker.
 */
#[QueueWorker(
  id: MailingListSubscriptionWorker::QUEUE_NAME,
  title: new TranslatableMarkup('mailing_list_subscription'),
  cron: ['time' => 60],
)]
final class MailingListSubscriptionWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The queue worker plugin ID, also used as the queue name.
   */
  const QUEUE_NAME = 'conreg_mailing_list_subscription';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected MailingListProviderPluginManager $providerManager,
    #[Autowire(service: 'logger.channel.conreg_mailing_list')]
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // Process a queue item. First get the Member and the Rule from the item.
    /** @var \Drupal\conreg\Member */
    $member = $data->member;
    /** @var \Drupal\conreg_mailing_list\Entity\ConregSubscriptionRule */
    $rule = $data->rule;
    // Get the provider from the provider manager, and attempt to subscribe.
    try {
      /** @var \Drupal\conreg_mailing_list\MailingListProviderInterface */
      $provider = $this->providerManager->createInstance($rule->getProvider());
      $provider->subscribe($member->email, $rule->getListId(), ['name' => $member->first_name . ' ' . $member->last_name]);
    }
    catch (MailingListTransientException $e) {
      // Log warning message.
      $this->logger->warning('Transient error subscribing member @first @last to mailing list @list. Will try again later.', [
        '@first' => $member->first_name,
        '@last' => $member->last_name,
        '@list' => $rule->getListId(),
      ]);
      // Throw exception to cause queue to be suspended. This will cause the
      // item to be put back on the queue, and no more items to be processed
      // during this cron run.
      throw new SuspendQueueException();
    }
    catch (MailingListPermanentException $e) {
      // Log error message, but take no further action so the queue item will
      // not be retried.
      $this->logger->error('Permanent error subscribing member @first @last to mailing list @list. No further attempts to subscribe this member will be made.', [
        '@first' => $member->first_name,
        '@last' => $member->last_name,
        '@list' => $rule->getListId(),
      ]);
    }
  }

}
