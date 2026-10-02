<?php

declare(strict_types=1);

namespace Drupal\conreg\Form\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\Service\RatePlanManager;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rate plan add/edit form.
 */
class RatePlanForm extends ContentEntityForm {

  use AutowireTrait;
  use RatePlanEventTrait;

  /**
   * The rate plan being edited.
   *
   * @var \Drupal\conreg\Entity\RatePlan
   */
  protected $entity;

  /**
   * Constructs a RatePlanForm object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\conreg\Service\ConregOptions $conregOptions
   *   The ConReg options service.
   * @param \Drupal\conreg\Service\EventStorage $eventStorage
   *   The event storage service.
   * @param \Drupal\conreg\Service\RatePlanManager $ratePlanManager
   *   The rate plan manager.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected ConregOptions $conregOptions,
    protected EventStorage $eventStorage,
    protected RatePlanManager $ratePlanManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $eid = $this->entity->getEventId();
    if (!$this->eventStorage->load(['eid' => $eid])) {
      throw new NotFoundHttpException();
    }

    $form['#attached']['library'][] = 'conreg/conreg_rate_plans';
    if (!$this->entity->isNew()) {
      $form['#title'] = $this->t('Edit the rate plan planned for @date', [
        '@date' => $this->ratePlanManager->formatPlannedDate($this->entity->getPlannedDate()),
      ]);
    }

    $memberTypes = $this->conregOptions->memberTypes($eid);
    $plannedPrices = $this->ratePlanManager->getPlanPrices($this->entity, $memberTypes);
    $plannedDayPrices = $this->ratePlanManager->getPlanDayPrices($this->entity, $memberTypes);
    $currentDayPrices = $this->ratePlanManager->getCurrentDayPrices($memberTypes);
    $names = $this->ratePlanManager->getMemberTypeNames($memberTypes);
    $dayNames = $this->ratePlanManager->getDayNames($memberTypes);
    $missing = [];
    $missingDays = [];
    if (!$this->entity->isNew()) {
      $missing = $this->ratePlanManager->getMissingPriceTypes($this->entity, $memberTypes);
      foreach ($this->ratePlanManager->getMissingDayPrices($this->entity, $memberTypes) as $days) {
        array_push($missingDays, ...array_values($days));
      }
    }

    $warnings = [];
    if ($missing) {
      $warnings[] = $this->missingPricesWarning(
        $this->t('These member types were added after this plan was created, so need a price:'),
        $missing,
      );
    }
    if ($missingDays) {
      $warnings[] = $this->missingPricesWarning(
        $this->t('These days were enabled after this plan was created, so need a price:'),
        $missingDays,
      );
    }
    if ($warnings) {
      $form['missing'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => $warnings],
        '#status_headings' => ['warning' => $this->t('Warning message')],
      ];
    }

    $form['planned_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Planned date'),
      '#description' => $this->t('The date the prices are planned to change, in the convention timezone (@timezone). The plan is <em>not</em> applied automatically on this date: once it is due, apply it from the planned rate plans list.', [
        '@timezone' => $this->ratePlanManager->getTimezone()->getName(),
      ]),
      '#default_value' => $this->entity->getPlannedDate(),
      '#required' => TRUE,
    ];

    $form['prices'] = [
      '#type' => 'table',
      '#caption' => $this->t('Prices'),
      '#header' => [
        $this->t('Member type'),
        $this->t('Current price'),
        $this->t('Planned price'),
      ],
      '#empty' => $this->t('This event has no member types.'),
      '#tree' => TRUE,
    ];
    $currencySymbol = $this->ratePlanManager->getCurrencySymbol($eid);
    foreach ($memberTypes->types as $type => $memberType) {
      $name = $names[$type];
      $currentPrice = is_numeric($memberType->price ?? NULL) ? $memberType->price : '';
      $form['prices'][$type]['name'] = [
        '#plain_text' => $name,
      ];
      $form['prices'][$type]['current'] = [
        '#markup' => $this->ratePlanManager->formatPrice($eid, $memberType->price ?? NULL),
      ];
      $form['prices'][$type]['price'] = [
        '#title' => $this->t('Planned price for @type', ['@type' => $name]),
        // A new plan starts from the current prices. On an existing plan,
        // member types added since it was saved are left blank, so their
        // price is chosen deliberately.
        '#default_value' => $this->entity->isNew() ? $currentPrice : ($plannedPrices[$type] ?? ''),
      ] + $this->priceElement($currencySymbol);

      // Each enabled day has a row of its own under its member type. The day
      // prices are kept apart from the main prices in the submitted values.
      foreach ($plannedDayPrices[$type] ?? [] as $day => $plannedDayPrice) {
        $dayName = $dayNames[$type][$day];
        $row = "day:$type:$day";
        $form['prices'][$row]['#attributes']['class'][] = 'conreg-rate-plan__day';
        $form['prices'][$row]['name'] = [
          '#markup' => $this->ratePlanManager->dayRowLabel($name, $dayName),
        ];
        $form['prices'][$row]['current'] = [
          '#markup' => $this->ratePlanManager->formatPrice($eid, $currentDayPrices[$type][$day]),
        ];
        $form['prices'][$row]['price'] = [
          '#title' => $this->t('Planned @day price for @type', ['@day' => $dayName, '@type' => $name]),
          '#parents' => ['day_prices', $type, $day],
          // As for member types, days enabled since the plan was saved are
          // left blank. A day with no current price is free, so starts at 0.
          '#default_value' => $this->entity->isNew() ? $currentDayPrices[$type][$day] : ($plannedDayPrice ?? ''),
        ] + $this->priceElement($currencySymbol);
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    $date = $form_state->getValue('planned_date');
    // PHP rolls invalid dates over, e.g. 2026-02-31 to 2026-03-03, so check
    // the date is unchanged by parsing it.
    $parsed = $date ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : FALSE;
    if ($date && (!$parsed || $parsed->format('Y-m-d') !== $date)) {
      $form_state->setErrorByName('planned_date', $this->t('Enter a valid date.'));
    }
    return parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * The date and prices use their own form elements, not field widgets.
   */
  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state): void {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    /** @var \Drupal\conreg\Entity\RatePlan $entity */
    $entity->set('planned_date', $form_state->getValue('planned_date'));
    // Day rows have no values here, as their prices are under day_prices.
    $prices = array_map(fn(array $row) => $row['price'], array_filter(
      $form_state->getValue('prices') ?? [],
      fn($row) => is_array($row) && array_key_exists('price', $row),
    ));
    // Replaces the plan's prices, so any for member types deleted since it was
    // saved are tidied away.
    $entity->setPrices($prices);
    // Likewise days disabled since it was saved.
    $entity->setDayPrices($form_state->getValue('day_prices') ?? []);
  }

  /**
   * Build a warning listing the member types or days that need a price.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $intro
   *   The text introducing the list.
   * @param array $items
   *   The names of the member types or days.
   */
  protected function missingPricesWarning(TranslatableMarkup $intro, array $items): array {
    return [
      'intro' => ['#markup' => $intro],
      'list' => [
        '#theme' => 'item_list',
        '#items' => array_values($items),
      ],
    ];
  }

  /**
   * Build a planned price input.
   *
   * @param string $currencySymbol
   *   The currency symbol shown before the input.
   */
  protected function priceElement(string $currencySymbol): array {
    return [
      '#type' => 'number',
      '#title_display' => 'invisible',
      '#field_prefix' => $currencySymbol,
      '#wrapper_attributes' => ['class' => ['conreg-rate-plan__price']],
      '#step' => '0.01',
      '#min' => 0,
      // The largest price the decimal(10,2) price column can hold.
      '#max' => 99999999.99,
      '#size' => 10,
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    $this->messenger()->addStatus($this->t('Rate plan for @date saved.', [
      '@date' => $this->ratePlanManager->formatPlannedDate($this->entity->getPlannedDate()),
    ]));
    return $result;
  }

}
