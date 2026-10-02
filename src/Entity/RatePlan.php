<?php

declare(strict_types=1);

namespace Drupal\conreg\Entity;

use Drupal\conreg\Form\Admin\RatePlanApplyForm;
use Drupal\conreg\Form\Admin\RatePlanDeleteForm;
use Drupal\conreg\Form\Admin\RatePlanForm;
use Drupal\conreg\PlannedRatePlanListBuilder;
use Drupal\conreg\RatePlanAccessControlHandler;
use Drupal\conreg\RatePlanStorage;
use Drupal\conreg\Service\RatePlanManager;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the rate plan entity.
 *
 * A rate plan sets the price of every member type of an event, and of each of
 * their enabled days, on a planned date. It is applied by an administrator,
 * after which it can't be edited or deleted, and is kept as a record of the
 * price change.
 */
#[ContentEntityType(
  id: 'conreg_rate_plan',
  label: new TranslatableMarkup('Rate plan'),
  label_collection: new TranslatableMarkup('Rate plans'),
  label_singular: new TranslatableMarkup('rate plan'),
  label_plural: new TranslatableMarkup('rate plans'),
  base_table: 'conreg_rate_plan',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage' => RatePlanStorage::class,
    'access' => RatePlanAccessControlHandler::class,
    'list_builder' => PlannedRatePlanListBuilder::class,
    'form' => [
      'add' => RatePlanForm::class,
      'edit' => RatePlanForm::class,
      'delete' => RatePlanDeleteForm::class,
      'apply' => RatePlanApplyForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/conreg/rate-plans/{eid}',
    'add-form' => '/admin/config/conreg/rate-plans/{eid}/add',
    'edit-form' => '/admin/config/conreg/rate-plans/{eid}/{conreg_rate_plan}/edit',
    'delete-form' => '/admin/config/conreg/rate-plans/{eid}/{conreg_rate_plan}/delete',
    'apply-form' => '/admin/config/conreg/rate-plans/{eid}/{conreg_rate_plan}/apply',
  ],
  admin_permission: 'configure convention registration',
)]
class RatePlan extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['eid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Event ID'))
      ->setDescription(t('The event whose member type prices the plan sets.'))
      ->setRequired(TRUE);

    $fields['planned_date'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Planned date'))
      ->setDescription(t('The date the plan is intended to be applied (Y-m-d, convention timezone).'))
      ->setSetting('max_length', 10)
      ->setRequired(TRUE);

    $fields['prices'] = BaseFieldDefinition::create('conreg_member_type_price')
      ->setLabel(t('Prices'))
      ->setDescription(t('The planned price for each member type, and for each of its enabled days. Member types added, and days enabled, after the plan was saved have no price.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $fields['applied'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Applied'))
      ->setDescription(t('When the plan was applied, or empty if it has not been.'));

    $fields['applied_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Applied by'))
      ->setDescription(t('The user who applied the plan.'))
      ->setSetting('target_type', 'user');

    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * The label includes the planned date written out in words, e.g. "Rate plan
   * for 1 November 2026", so it reads naturally in screen reader labels such
   * as "Edit Rate plan for 1 November 2026".
   */
  public function label(): string {
    $date = $this->getPlannedDate();
    if ($date === '') {
      return (string) $this->getEntityType()->getLabel();
    }
    return (string) new TranslatableMarkup('Rate plan for @date', [
      '@date' => \Drupal::service(RatePlanManager::class)->formatPlannedDate($date, withWeekday: FALSE),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * Rate plan URLs sit under their event's rate plan pages.
   */
  protected function urlRouteParameters($rel): array {
    $parameters = parent::urlRouteParameters($rel);
    $parameters['eid'] = $this->getEventId();
    return $parameters;
  }

  /**
   * The ID of the event the plan belongs to.
   */
  public function getEventId(): int {
    return (int) $this->get('eid')->value;
  }

  /**
   * The planned date, in Y-m-d format.
   */
  public function getPlannedDate(): string {
    return (string) $this->get('planned_date')->value;
  }

  /**
   * Whether the plan has been applied.
   */
  public function isApplied(): bool {
    return !$this->get('applied')->isEmpty();
  }

  /**
   * When the plan was applied, or NULL if it hasn't been.
   */
  public function getAppliedTime(): ?int {
    return $this->isApplied() ? (int) $this->get('applied')->value : NULL;
  }

  /**
   * The planned main prices.
   *
   * @return string[]
   *   Prices keyed by member type code.
   */
  public function getPrices(): array {
    $prices = [];
    foreach ($this->get('prices') as $item) {
      if (!static::isDayItem($item)) {
        $prices[$item->member_type] = $item->price;
      }
    }
    return $prices;
  }

  /**
   * The planned day prices.
   *
   * @return string[][]
   *   Prices keyed by member type code, then by day code.
   */
  public function getDayPrices(): array {
    $prices = [];
    foreach ($this->get('prices') as $item) {
      if (static::isDayItem($item)) {
        $prices[$item->member_type][$item->day] = $item->price;
      }
    }
    return $prices;
  }

  /**
   * The main prices the member types had before the plan was applied.
   *
   * @return array
   *   Prices keyed by member type code. NULL for member types that had no
   *   price, and for every member type if the plan hasn't been applied.
   */
  public function getPreviousPrices(): array {
    $prices = [];
    foreach ($this->get('prices') as $item) {
      if (!static::isDayItem($item)) {
        $prices[$item->member_type] = $item->previous_price;
      }
    }
    return $prices;
  }

  /**
   * The day prices the member types had before the plan was applied.
   *
   * @return array
   *   Prices keyed by member type code, then by day code. A day that had no
   *   price was free, so is given as 0. NULL for every day if the plan hasn't
   *   been applied.
   */
  public function getPreviousDayPrices(): array {
    $prices = [];
    foreach ($this->get('prices') as $item) {
      if (static::isDayItem($item)) {
        $prices[$item->member_type][$item->day] = $item->previous_price;
      }
    }
    return $prices;
  }

  /**
   * Set the planned main prices, replacing the plan's existing ones.
   *
   * The plan's day prices are kept.
   *
   * @param string[] $prices
   *   Prices keyed by member type code. Member types not included, such as
   *   ones that have been deleted, are removed from the plan.
   *
   * @return $this
   */
  public function setPrices(array $prices): static {
    return $this->setAllPrices($prices, $this->getDayPrices());
  }

  /**
   * Set the planned day prices, replacing the plan's existing ones.
   *
   * The plan's main prices are kept.
   *
   * @param string[][] $prices
   *   Prices keyed by member type code, then by day code. Days not included,
   *   such as ones that have been disabled, are removed from the plan.
   *
   * @return $this
   */
  public function setDayPrices(array $prices): static {
    return $this->setAllPrices($this->getPrices(), $prices);
  }

  /**
   * Record that the plan has been applied.
   *
   * @param array $previousPrices
   *   The main prices before the plan was applied, keyed by member type code.
   * @param array $previousDayPrices
   *   The day prices before the plan was applied, keyed by member type code,
   *   then by day code.
   * @param int $uid
   *   The ID of the user who applied the plan.
   * @param int $timestamp
   *   The time the plan was applied.
   *
   * @return $this
   */
  public function markApplied(array $previousPrices, array $previousDayPrices, int $uid, int $timestamp): static {
    foreach ($this->get('prices') as $item) {
      $item->previous_price = static::isDayItem($item)
        ? $previousDayPrices[$item->member_type][$item->day] ?? NULL
        : $previousPrices[$item->member_type] ?? NULL;
    }
    $this->set('applied', $timestamp);
    $this->set('applied_by', $uid);
    return $this;
  }

  /**
   * The member type names recorded when the plan was applied.
   *
   * @return array
   *   Names keyed by member type code. NULL for member types whose name wasn't
   *   recorded, and for every member type if the plan hasn't been applied.
   */
  public function getMemberTypeNames(): array {
    $names = [];
    foreach ($this->get('prices') as $item) {
      if (!static::isDayItem($item)) {
        $names[$item->member_type] = $item->member_type_name;
      }
    }
    return $names;
  }

  /**
   * The day names recorded when the plan was applied.
   *
   * @return array
   *   Names keyed by member type code, then by day code. NULL for days whose
   *   name wasn't recorded, and for every day if the plan hasn't been applied.
   */
  public function getDayNames(): array {
    $names = [];
    foreach ($this->get('prices') as $item) {
      if (static::isDayItem($item)) {
        $names[$item->member_type][$item->day] = $item->day_name;
      }
    }
    return $names;
  }

  /**
   * Record the member type and day names, as part of applying the plan.
   *
   * @param string[] $names
   *   Member type names keyed by member type code.
   * @param string[][] $dayNames
   *   Day names keyed by member type code, then by day code.
   *
   * @return $this
   */
  public function setNames(array $names, array $dayNames): static {
    foreach ($this->get('prices') as $item) {
      $item->member_type_name = $names[$item->member_type] ?? NULL;
      $item->day_name = static::isDayItem($item)
        ? $dayNames[$item->member_type][$item->day] ?? NULL
        : NULL;
    }
    return $this;
  }

  /**
   * Replace all the plan's prices.
   *
   * @param string[] $prices
   *   Main prices keyed by member type code.
   * @param string[][] $dayPrices
   *   Day prices keyed by member type code, then by day code.
   *
   * @return $this
   */
  protected function setAllPrices(array $prices, array $dayPrices): static {
    $items = [];
    foreach ($prices as $type => $price) {
      $items[] = ['member_type' => (string) $type, 'day' => NULL, 'price' => $price];
    }
    foreach ($dayPrices as $type => $days) {
      foreach ($days as $day => $price) {
        $items[] = ['member_type' => (string) $type, 'day' => (string) $day, 'price' => $price];
      }
    }
    $this->set('prices', $items);
    return $this;
  }

  /**
   * Whether a price item is a day price, rather than a main price.
   */
  protected static function isDayItem(FieldItemInterface $item): bool {
    return $item->day !== NULL && $item->day !== '';
  }

}
