<?php

declare(strict_types=1);

namespace Drupal\conreg;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Lists an event's rate plans that have not yet been applied.
 *
 * This is the rate plan collection.
 */
final class PlannedRatePlanListBuilder extends RatePlanListBuilderBase {

  /**
   * {@inheritdoc}
   *
   * Each plan's price changes depend on the plans before it, so they are all
   * listed on one page.
   */
  protected $limit = FALSE;

  /**
   * The prices the next plan's changes are shown from.
   *
   * Starts as the current prices, and becomes each plan's prices as its row is
   * built, so a plan's changes are shown as if the plans before it have been
   * applied.
   */
  protected array $basePrices = [];

  /**
   * The day prices the next plan's changes are shown from.
   *
   * As $basePrices, keyed by member type code then day code.
   */
  protected array $baseDayPrices = [];

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    $this->basePrices = [];
    foreach ($this->memberTypes()->types as $type => $memberType) {
      $this->basePrices[$type] = $memberType->price ?? NULL;
    }
    $this->baseDayPrices = $this->ratePlanManager->getCurrentDayPrices($this->memberTypes());
    return $this->storage->loadPlanned($this->eid);
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['planned_date'] = $this->t('Planned date');
    $header['when'] = $this->t('When');
    $header['prices'] = $this->t('Price changes');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\conreg\Entity\RatePlan $entity */
    $memberTypes = $this->memberTypes();
    $hasMissing = $this->ratePlanManager->getMissingPriceTypes($entity, $memberTypes)
      || $this->ratePlanManager->getMissingDayPrices($entity, $memberTypes);
    $days = $this->ratePlanManager->daysUntil($entity->getPlannedDate());
    $planPrices = $this->ratePlanManager->getPlanPrices($entity, $memberTypes);
    $planDayPrices = $this->ratePlanManager->getPlanDayPrices($entity, $memberTypes);

    // Only prices the plan would change, or that still need setting, are
    // listed.
    $prices = $this->buildPriceChanges(
      $this->basePrices,
      $planPrices,
      $this->baseDayPrices,
      $planDayPrices,
      $this->ratePlanManager->getMemberTypeNames($memberTypes),
      $this->ratePlanManager->getDayNames($memberTypes),
      hideUnchanged: TRUE,
    );

    // Later plans start from this plan's prices.
    $isSet = fn($price) => $price !== NULL;
    $this->basePrices = array_replace($this->basePrices, array_filter($planPrices, $isSet));
    foreach ($planDayPrices as $type => $dayPrices) {
      $this->baseDayPrices[$type] = array_replace($this->baseDayPrices[$type] ?? [], array_filter($dayPrices, $isSet));
    }

    // Highlight rows that need attention the way core does, e.g. on the
    // available updates report: an error for a plan that can't be applied,
    // and a warning for one that is overdue.
    $classes = [];
    if ($hasMissing) {
      $classes[] = 'color-error';
    }
    elseif ($days < 0) {
      $classes[] = 'color-warning';
    }
    if ($days <= 0) {
      $classes[] = 'conreg-rate-plan--due';
    }

    $row['planned_date'] = $this->ratePlanManager->formatPlannedDate($entity->getPlannedDate());
    $row['when'] = [
      'data' => $this->ratePlanManager->relativeDate($entity->getPlannedDate()),
      'class' => ['conreg-rate-plan__when'],
    ];
    $row['prices'] = ['data' => $prices];
    return [
      'data' => $row + parent::buildRow($entity),
      'class' => $classes,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $cacheability ??= new CacheableMetadata();
    $operations = parent::getDefaultOperations($entity, $cacheability);

    /** @var \Drupal\conreg\Entity\RatePlan $entity */
    // Plans missing prices, or that wouldn't change any, are forbidden.
    $applyAccess = $entity->access('apply', return_as_object: TRUE);
    $cacheability->addCacheableDependency($applyAccess);
    if ($applyAccess->isAllowed()) {
      $operations['apply'] = [
        'title' => $this->t('Apply'),
        // Once a plan is due, applying it is the next step, so it comes before
        // Edit and is the visible operation. Before then it can still be
        // applied early from the dropdown.
        'weight' => $this->ratePlanManager->daysUntil($entity->getPlannedDate()) <= 0 ? 0 : 20,
        // No destination: once applied, the plan is shown in the applied list.
        'url' => $entity->toUrl('apply-form'),
        'attributes' => [
          // Matches the labels core gives the Edit and Delete operations.
          'aria-label' => $this->t('Apply @entity_label', ['@entity_label' => $entity->label()]),
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode(['width' => 880]),
        ],
      ];
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Rate plans set the price of every member type, and of each of their days, on a planned date. Price changes assume the plans before them have been applied. Dates are in the convention timezone (@timezone).', [
          '@timezone' => $this->ratePlanManager->getTimezone()->getName(),
        ]),
      ],
    ] + parent::render();
  }

  /**
   * {@inheritdoc}
   */
  protected function pageTitle(string $eventName): TranslatableMarkup {
    return $this->t('@event_name rate plans', ['@event_name' => $eventName]);
  }

  /**
   * {@inheritdoc}
   */
  protected function emptyText(): TranslatableMarkup {
    return $this->t('There are no planned rate plans. Add one to plan a change to member type prices.');
  }

}
