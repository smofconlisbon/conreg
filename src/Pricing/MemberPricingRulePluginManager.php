<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

use Drupal\conreg\Pricing\Attribute\MemberPricingRule;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * MemberPricingRule plugin manager.
 */
final class MemberPricingRulePluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/MemberPricingRule', $namespaces, $module_handler, MemberPricingRuleInterface::class, MemberPricingRule::class);
    $this->alterInfo('member_pricing_rule_info');
    $this->setCacheBackend($cache_backend, 'member_pricing_rule_plugins');
  }

  /**
   * Returns all rule instances, sorted in ascending weight order.
   *
   * @return \Drupal\conreg\Pricing\MemberPricingRuleInterface[]
   *   The rule instances.
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
