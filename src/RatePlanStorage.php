<?php

declare(strict_types=1);

namespace Drupal\conreg;

use Drupal\conreg\Entity\RatePlan;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Storage handler for rate plans.
 */
class RatePlanStorage extends SqlContentEntityStorage {

  /**
   * Query for an event's plans that have not yet been applied.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query, ordered by planned date.
   */
  public function plannedQuery(int $eid): QueryInterface {
    return $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('eid', $eid)
      ->notExists('applied')
      ->sort('planned_date')
      ->sort('id');
  }

  /**
   * Query for an event's plans that have been applied.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query, most recently applied first.
   */
  public function appliedQuery(int $eid): QueryInterface {
    return $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('eid', $eid)
      ->exists('applied')
      ->sort('applied', 'DESC')
      ->sort('id', 'DESC');
  }

  /**
   * Load an event's plans that have not yet been applied.
   *
   * @return \Drupal\conreg\Entity\RatePlan[]
   *   The plans, ordered by planned date.
   */
  public function loadPlanned(int $eid): array {
    return $this->loadMultiple($this->plannedQuery($eid)->execute());
  }

  /**
   * Count the plans listed before a plan that have not yet been applied.
   *
   * Plans are listed by planned date, and plans on the same date by ID.
   */
  public function countPlannedBefore(RatePlan $plan): int {
    $query = $this->plannedQuery($plan->getEventId());
    $before = $query->orConditionGroup()
      ->condition('planned_date', $plan->getPlannedDate(), '<')
      ->condition($query->andConditionGroup()
        ->condition('planned_date', $plan->getPlannedDate())
        ->condition('id', $plan->id(), '<'));
    return (int) $query->condition($before)->count()->execute();
  }

}
