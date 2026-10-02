<?php

declare(strict_types=1);

namespace Drupal\conreg\Controller;

use Drupal\conreg\AppliedRatePlanListBuilder;
use Drupal\Core\Controller\ControllerBase;

/**
 * Lists an event's applied rate plans.
 *
 * The planned rate plans are the entity type's collection, so are listed by
 * its list builder through the _entity_list route default. An entity type has
 * only one list builder handler, so the applied list's builder is rendered
 * here instead.
 */
class RatePlanController extends ControllerBase {

  /**
   * List rate plans that have been applied, most recent first.
   */
  public function applied(): array {
    return $this->entityTypeManager()
      ->createHandlerInstance(AppliedRatePlanListBuilder::class, $this->entityTypeManager()->getDefinition('conreg_rate_plan'))
      ->render();
  }

}
