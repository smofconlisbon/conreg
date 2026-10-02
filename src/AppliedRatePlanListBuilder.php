<?php

declare(strict_types=1);

namespace Drupal\conreg;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Lists an event's rate plans that have been applied, most recent first.
 *
 * Applied plans can't be changed, so the list has no operations.
 */
final class AppliedRatePlanListBuilder extends RatePlanListBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    return $this->storage->appliedQuery($this->eid)
      ->pager($this->limit)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['planned_date'] = $this->t('Planned date');
    $header['applied'] = $this->t('Applied');
    $header['applied_by'] = $this->t('Applied by');
    $header['prices'] = $this->t('Price changes');
    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\conreg\Entity\RatePlan $entity */
    $memberTypes = $this->memberTypes();

    // Every price is listed in the order the plan stored them, as the record
    // of what the plan set, including those of member types deleted and days
    // disabled since. They're named as they were when the plan was applied,
    // or as they are now if the names weren't recorded.
    $names = array_filter($entity->getMemberTypeNames(), fn($name) => $name !== NULL)
      + $this->ratePlanManager->getMemberTypeNames($memberTypes);
    $dayNames = $this->ratePlanManager->getDayNames($memberTypes);
    foreach ($entity->getDayNames() as $type => $days) {
      $dayNames[$type] = array_filter($days, fn($name) => $name !== NULL) + ($dayNames[$type] ?? []);
    }

    $user = $entity->get('applied_by')->entity;
    $row['planned_date'] = $this->ratePlanManager->formatPlannedDate($entity->getPlannedDate());
    $row['applied'] = $this->ratePlanManager->formatTimestamp($entity->getAppliedTime());
    $row['applied_by'] = [
      'data' => $user
        ? ['#theme' => 'username', '#account' => $user]
        : ['#markup' => $this->t('Unknown user')],
    ];
    $row['prices'] = [
      'data' => $this->buildPriceChanges(
        $entity->getPreviousPrices(),
        $entity->getPrices(),
        $entity->getPreviousDayPrices(),
        $entity->getDayPrices(),
        $names,
        $dayNames,
        hideUnchanged: FALSE,
      ),
    ];
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('A record of the rate plans that have been applied, and the prices they changed. Prices changed directly on the Member Types tab, rather than by applying a rate plan, are not shown.'),
      ],
    ] + parent::render();
  }

  /**
   * {@inheritdoc}
   */
  protected function pageTitle(string $eventName): TranslatableMarkup {
    return $this->t('@event_name applied rate plans', ['@event_name' => $eventName]);
  }

  /**
   * {@inheritdoc}
   */
  protected function emptyText(): TranslatableMarkup {
    return $this->t('No rate plans have been applied.');
  }

}
