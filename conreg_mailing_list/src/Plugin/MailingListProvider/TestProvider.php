<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list\Plugin\MailingListProvider;

use Drupal\conreg_mailing_list\Attribute\MailingListProvider;
use Drupal\conreg_mailing_list\MailingListProviderPluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

// cspell:ignore testprovider

/**
 * Plugin implementation of the mailing_list_provider.
 */
#[MailingListProvider(
  id: 'testprovider',
  label: new TranslatableMarkup('Test Provider'),
  description: new TranslatableMarkup('A simple test mailing list provider.'),
)]
final class TestProvider extends MailingListProviderPluginBase {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getLists(): array {
    return [1 => $this->t('Test list')];
  }

  /**
   * {@inheritdoc}
   */
  public function subscribe(string $email, string $listId, array $fields): void {
    $this->logger->info(
      'Subscribing member @email to @provider list @list',
      ['@email' => $email, '@provider' => $this->getPluginId(), '@list' => $listId],
    );
  }

}
