<?php

declare(strict_types=1);

namespace Drupal\conreg\Form\Admin;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ties rate plan entity forms to the event in their URL.
 *
 * Rate plan URLs include the event ID. New plans are created for that event,
 * and existing plans are only found under their own event.
 */
trait RatePlanEventTrait {

  /**
   * {@inheritdoc}
   */
  public function getEntityFromRouteMatch(RouteMatchInterface $route_match, $entity_type_id) {
    $eid = (int) $route_match->getRawParameter('eid');
    /** @var \Drupal\conreg\Entity\RatePlan $entity */
    $entity = parent::getEntityFromRouteMatch($route_match, $entity_type_id);
    if ($entity->isNew()) {
      $entity->set('eid', $eid);
    }
    elseif ($entity->getEventId() !== $eid) {
      throw new NotFoundHttpException();
    }
    return $entity;
  }

}
