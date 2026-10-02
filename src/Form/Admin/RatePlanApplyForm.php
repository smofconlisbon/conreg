<?php

declare(strict_types=1);

namespace Drupal\conreg\Form\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Exception\RatePlanApplyException;
use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\RatePlanManager;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Confirm applying a rate plan, showing how each price will change.
 */
class RatePlanApplyForm extends ContentEntityConfirmFormBase {

  use AutowireTrait;
  use RatePlanEventTrait;

  /**
   * The rate plan being applied.
   *
   * @var \Drupal\conreg\Entity\RatePlan
   */
  protected $entity;

  /**
   * Constructs a RatePlanApplyForm object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\conreg\Service\ConregOptions $conregOptions
   *   The ConReg options service.
   * @param \Drupal\conreg\Service\RatePlanManager $ratePlanManager
   *   The rate plan manager.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected ConregOptions $conregOptions,
    protected RatePlanManager $ratePlanManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form['#attached']['library'][] = 'conreg/conreg_rate_plans';

    $eid = $this->entity->getEventId();
    $memberTypes = $this->conregOptions->memberTypes($eid);
    $planDayPrices = $this->ratePlanManager->getPlanDayPrices($this->entity, $memberTypes);
    $currentDayPrices = $this->ratePlanManager->getCurrentDayPrices($memberTypes);
    $names = $this->ratePlanManager->getMemberTypeNames($memberTypes);
    $dayNames = $this->ratePlanManager->getDayNames($memberTypes);
    $rows = [];
    $changedCount = 0;
    $changedDayCount = 0;
    foreach ($this->ratePlanManager->getPlanPrices($this->entity, $memberTypes) as $type => $price) {
      $name = $names[$type];
      $current = $memberTypes->types[$type]->price ?? NULL;
      $changed = !$this->ratePlanManager->pricesEqual($current, $price);
      if ($changed) {
        $changedCount++;
      }
      $rows[] = $this->buildRow($eid, $name, $current, $price, $changed);

      // Each day's row follows its member type's.
      foreach ($planDayPrices[$type] ?? [] as $day => $dayPrice) {
        $currentDayPrice = $currentDayPrices[$type][$day];
        $dayChanged = !$this->ratePlanManager->pricesEqual($currentDayPrice, $dayPrice);
        if ($dayChanged) {
          $changedDayCount++;
        }
        $label = ['#markup' => $this->ratePlanManager->dayRowLabel($name, $dayNames[$type][$day])];
        $row = $this->buildRow($eid, $label, $currentDayPrice, $dayPrice, $dayChanged);
        $row['class'][] = 'conreg-rate-plan__day';
        $rows[] = $row;
      }
    }

    // Plans can be applied in any order, so warn that applying an earlier plan
    // afterwards would undo this one.
    /** @var \Drupal\conreg\RatePlanStorage $storage */
    $storage = $this->entityTypeManager->getStorage('conreg_rate_plan');
    if ($earlier = $storage->countPlannedBefore($this->entity)) {
      $form['earlier'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            $this->formatPlural($earlier,
              'There is an unapplied rate plan planned before this one.',
              'There are unapplied rate plans planned before this one.'),
          ],
        ],
        '#status_headings' => ['warning' => $this->t('Warning message')],
        '#weight' => -11,
      ];
    }

    $form['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->summary($changedCount, $changedDayCount),
      '#weight' => -10,
    ];
    $form['prices'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Member type'),
        $this->t('Current price'),
        $this->t('New price'),
        $this->t('Change'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['conreg-rate-plan__changes']],
      '#weight' => -9,
    ];

    return $form;
  }

  /**
   * Build a row of the price changes table.
   *
   * @param int $eid
   *   The event ID.
   * @param string|array $label
   *   The member type or day the price is for, as text or a render array.
   * @param mixed $current
   *   The current price.
   * @param mixed $price
   *   The plan's price.
   * @param bool $changed
   *   Whether the plan changes the price.
   */
  protected function buildRow(int $eid, string|array $label, mixed $current, mixed $price, bool $changed): array {
    return [
      'data' => [
        is_array($label) ? ['data' => $label] : $label,
        $this->ratePlanManager->formatPrice($eid, $current),
        $this->ratePlanManager->formatPrice($eid, $price),
        $this->ratePlanManager->formatPriceChange($eid, $current, $price),
      ],
      'class' => $changed ? ['conreg-rate-plan__changed'] : ['conreg-rate-plan__unchanged'],
    ];
  }

  /**
   * Summarize how many prices applying the plan will change.
   *
   * @param int $typeCount
   *   The number of member type main prices that will change.
   * @param int $dayCount
   *   The number of day prices that will change.
   */
  protected function summary(int $typeCount, int $dayCount): TranslatableMarkup {
    if ($dayCount === 0) {
      return $this->formatPlural($typeCount,
        'Applying this plan will change the price of 1 member type.',
        'Applying this plan will change the price of @count member types.');
    }
    $days = $this->formatPlural($dayCount, '1 day price', '@count day prices');
    if ($typeCount === 0) {
      return $this->t('Applying this plan will change @days.', ['@days' => $days]);
    }
    return $this->t('Applying this plan will change @types and @days.', [
      '@types' => $this->formatPlural($typeCount, '1 member type price', '@count member type prices'),
      '@days' => $days,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Apply the rate plan planned for @date?', [
      '@date' => $this->ratePlanManager->formatPlannedDate($this->entity->getPlannedDate()),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Member type and day prices will be updated immediately. This cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Apply rate plan');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $eid = $this->entity->getEventId();
    try {
      $this->ratePlanManager->apply($this->entity);
    }
    catch (RatePlanApplyException $e) {
      $this->messenger()->addError($this->t('The rate plan could not be applied. @reason', ['@reason' => $e->getReason()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }
    $this->messenger()->addStatus($this->t('Rate plan applied. Member type prices have been updated.'));
    $form_state->setRedirect('conreg_rate_plans_applied', ['eid' => $eid]);
  }

}
