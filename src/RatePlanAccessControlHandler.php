<?php

declare(strict_types=1);

namespace Drupal\conreg;

use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\RatePlanManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access control for rate plans.
 *
 * Applied plans are the record of price changes, so can't be edited, applied
 * again or deleted. Plans missing a price for a member type or enabled day, or
 * that wouldn't change any prices, can't be applied.
 */
class RatePlanAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Constructs a RatePlanAccessControlHandler object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The rate plan entity type.
   * @param \Drupal\conreg\Service\ConregOptions $conregOptions
   *   The ConReg options service.
   * @param \Drupal\conreg\Service\RatePlanManager $ratePlanManager
   *   The rate plan manager.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    protected ConregOptions $conregOptions,
    protected RatePlanManager $ratePlanManager,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get(ConregOptions::class),
      $container->get(RatePlanManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    /** @var \Drupal\conreg\Entity\RatePlan $entity */
    if (in_array($operation, ['update', 'delete', 'apply'], TRUE) && $entity->isApplied()) {
      return AccessResult::forbidden('Applied rate plans can not be changed.')->addCacheableDependency($entity);
    }
    if ($operation === 'apply') {
      // Whether the plan can be applied depends on the event's member types.
      $eid = $entity->getEventId();
      $cacheTags = ['config:conreg.settings.' . $eid, 'event:' . $eid . ':type'];
      $memberTypes = $this->conregOptions->memberTypes($eid);
      if ($this->ratePlanManager->getMissingPriceTypes($entity, $memberTypes)
        || $this->ratePlanManager->getMissingDayPrices($entity, $memberTypes)) {
        return AccessResult::forbidden('The rate plan has no price for some member types or days.')
          ->addCacheableDependency($entity)
          ->addCacheTags($cacheTags);
      }
      if (!$this->ratePlanManager->hasPriceChanges($entity, $memberTypes)) {
        return AccessResult::forbidden("The rate plan doesn't change any prices.")
          ->addCacheableDependency($entity)
          ->addCacheTags($cacheTags);
      }
      return parent::checkAccess($entity, $operation, $account)
        ->addCacheableDependency($entity)
        ->addCacheTags($cacheTags);
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
