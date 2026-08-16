<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list;

use Drupal\conreg_mailing_list\Attribute\MailingListProvider;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * MailingListProvider plugin manager.
 */
final class MailingListProviderPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/MailingListProvider', $namespaces, $module_handler, MailingListProviderInterface::class, MailingListProvider::class);
    $this->alterInfo('mailing_list_provider_info');
    $this->setCacheBackend($cache_backend, 'mailing_list_provider_plugins');
  }

}
