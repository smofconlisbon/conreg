<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of subscription rules.
 */
final class ConregSubscriptionRuleListBuilder extends ConfigEntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entityTypeManager,
    protected CurrentRouteMatch $routeMatch,
  ) {
    parent::__construct($entity_type, $entityTypeManager->getStorage($entity_type->id()));
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    // Get the event ID from the route.
    $eid = (int) $this->routeMatch->getParameter('eid');
    // Get subscription rule entities for the event ID.
    $ids = $this->getStorage()
      ->getQuery()
      ->condition('eid', $eid)
      ->sort($this->entityType->getKey('label'))
      ->accessCheck()
      ->execute();
    $entities = $this->storage->loadMultiple($ids);
    // Apply default sort.
    uasort($entities, [$this->entityType->getClass(), 'sort']);
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('Machine name');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface $entity */
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL) {
    $args = func_get_args();
    $cacheability = $args[1] ?? new CacheableMetadata();
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    $operations = parent::getDefaultOperations($entity, $cacheability);

    if ($this->entityType->hasKey('status')) {
      if (!$entity->status() && $entity->hasLinkTemplate('enable')) {
        $operations['enable'] = [
          'title' => $this->t('Enable'),
          'weight' => -10,
          'url' => $this->ensureDestination($entity->toUrl('enable')),
        ];
      }
      elseif ($entity->hasLinkTemplate('disable')) {
        $operations['disable'] = [
          'title' => $this->t('Disable'),
          'weight' => 40,
          'url' => $this->ensureDestination($entity->toUrl('disable')),
        ];
      }
    }

    /** @var \Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface $entity */
    $operations['sync'] = [
      'title' => $this->t('Sync now'),
      'weight' => 20,
      'url' => $this->ensureDestination(Url::fromRoute('entity.conreg_subscription_rule.sync', [
        'eid' => $entity->getEventId(),
        'conreg_subscription_rule' => $entity->id(),
      ])),
    ];

    return $operations;
  }

}
