<?php

declare(strict_types=1);

namespace Drupal\conreg\Form\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Service\RatePlanManager;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Confirm deletion of a rate plan that has not been applied.
 */
class RatePlanDeleteForm extends ContentEntityDeleteForm {

  use AutowireTrait;
  use RatePlanEventTrait;

  /**
   * The rate plan being deleted.
   *
   * @var \Drupal\conreg\Entity\RatePlan
   */
  protected $entity;

  /**
   * Constructs a RatePlanDeleteForm object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\conreg\Service\RatePlanManager $ratePlanManager
   *   The rate plan manager.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected RatePlanManager $ratePlanManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the rate plan planned for @date?', [
      '@date' => $this->ratePlanManager->formatPlannedDate($this->entity->getPlannedDate()),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeletionMessage(): TranslatableMarkup {
    return $this->t('Rate plan deleted.');
  }

}
