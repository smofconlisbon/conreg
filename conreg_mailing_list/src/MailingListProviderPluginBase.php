<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Base class for mailing_list_provider plugins.
 */
abstract class MailingListProviderPluginBase extends PluginBase implements ContainerFactoryPluginInterface, MailingListProviderInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    #[Autowire(service: 'logger.channel.conreg_mailing_list')]
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

}
