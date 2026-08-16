<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list_test\Plugin\MailingListProvider;

use Drupal\conreg_mailing_list\Attribute\MailingListProvider;
use Drupal\conreg_mailing_list\Exception\MailingListException;
use Drupal\conreg_mailing_list\Exception\MailingListPermanentException;
use Drupal\conreg_mailing_list\Exception\MailingListTransientException;
use Drupal\conreg_mailing_list\MailingListProviderPluginBase;
use Drupal\conreg_mailing_list_test\FakeProviderCallRecorder;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A controllable fake provider for kernel tests.
 */
#[MailingListProvider(
  id: 'fake_provider',
  label: new TranslatableMarkup('Fake Provider'),
  description: new TranslatableMarkup('A controllable fake provider used by kernel tests.'),
)]
final class FakeProvider extends MailingListProviderPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    LoggerInterface $logger,
    protected FakeProviderCallRecorder $recorder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.channel.conreg_mailing_list'),
      $container->get('conreg_mailing_list_test.recorder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLists(): array {
    if ($this->recorder->getListsFailure) {
      throw new MailingListException('Simulated getLists failure.');
    }
    return [1 => 'Fake list'];
  }

  /**
   * {@inheritdoc}
   */
  public function subscribe(string $email, string $listId, array $fields): void {
    $failure = $this->recorder->recordCall($email, $listId, $fields);

    match ($failure) {
      'transient' => throw new MailingListTransientException('Simulated transient failure.'),
      'permanent' => throw new MailingListPermanentException('Simulated permanent failure.'),
      default => NULL,
    };
  }

}
