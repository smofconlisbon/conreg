<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

use Drupal\conreg\Pricing\Attribute\PricingAdjustment;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * PricingAdjustment plugin manager.
 */
final class PricingAdjustmentPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/PricingAdjustment', $namespaces, $module_handler, PricingAdjustmentInterface::class, PricingAdjustment::class);
    $this->alterInfo('pricing_adjustment_info');
    $this->setCacheBackend($cache_backend, 'pricing_adjustment_plugins');
  }

  /**
   * Returns all adjustment instances, sorted in ascending weight order.
   *
   * @return \Drupal\conreg\Pricing\PricingAdjustmentInterface[]
   *   The adjustment instances.
   */
  public function getSortedInstances(): array {
    $definitions = $this->getDefinitions();
    uasort($definitions, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    $instances = [];
    foreach ($definitions as $id => $definition) {
      $instances[$id] = $this->createInstance($id);
    }
    return $instances;
  }

}
