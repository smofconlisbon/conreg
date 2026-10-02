<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Entity\RatePlan;
use Drupal\conreg\Exception\RatePlanApplyException;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Business logic for rate plans: missing prices, dates and applying plans.
 */
class RatePlanManager {

  use StringTranslationTrait;

  /**
   * Constructs a RatePlanManager object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $connection,
    // Core doesn't alias LockBackendInterface, so name the service.
    #[Autowire(service: 'lock')]
    protected LockBackendInterface $lock,
    protected ConfigFactoryInterface $configFactory,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected ConregOptions $conregOptions,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Get the timezone dates for the convention are expressed in.
   *
   * This is the site's default timezone.
   */
  public function getTimezone(): \DateTimeZone {
    $timezone = $this->configFactory->get('system.date')->get('timezone.default')
      ?: date_default_timezone_get();
    return new \DateTimeZone($timezone);
  }

  /**
   * Get the planned price for each of the event's current member types.
   *
   * Member types added after the plan was created have no stored price, so
   * are returned as NULL. Prices for member types that no longer exist are
   * omitted.
   *
   * @param \Drupal\conreg\Entity\RatePlan $plan
   *   The rate plan.
   * @param object $memberTypes
   *   The member types, as returned by ConregOptions::memberTypes().
   *
   * @return array
   *   Prices keyed by member type code, in member type order.
   */
  public function getPlanPrices(RatePlan $plan, object $memberTypes): array {
    $planned = $plan->getPrices();
    $prices = [];
    foreach (array_keys($memberTypes->types) as $type) {
      $prices[$type] = $planned[$type] ?? NULL;
    }
    return $prices;
  }

  /**
   * Get the names of member types the plan has no price for.
   *
   * @param \Drupal\conreg\Entity\RatePlan $plan
   *   The rate plan.
   * @param object $memberTypes
   *   The member types, as returned by ConregOptions::memberTypes().
   *
   * @return string[]
   *   Member type names keyed by member type code.
   */
  public function getMissingPriceTypes(RatePlan $plan, object $memberTypes): array {
    $names = $this->getMemberTypeNames($memberTypes);
    $missing = [];
    foreach ($this->getPlanPrices($plan, $memberTypes) as $type => $price) {
      if ($price === NULL) {
        $missing[$type] = $names[$type];
      }
    }
    return $missing;
  }

  /**
   * Get the planned price for each enabled day of the event's member types.
   *
   * Days enabled after the plan was saved have no stored price, so are
   * returned as NULL. Prices for days that are no longer enabled, or whose
   * member type no longer exists, are omitted.
   *
   * @param \Drupal\conreg\Entity\RatePlan $plan
   *   The rate plan.
   * @param object $memberTypes
   *   The member types, as returned by ConregOptions::memberTypes().
   *
   * @return array
   *   Prices keyed by member type code, then by day code, in member type and
   *   day order. Member types with no enabled days are left out.
   */
  public function getPlanDayPrices(RatePlan $plan, object $memberTypes): array {
    $planned = $plan->getDayPrices();
    $prices = [];
    foreach ($memberTypes->types as $type => $memberType) {
      foreach (array_keys($memberType->days ?? []) as $day) {
        $prices[$type][$day] = $planned[$type][$day] ?? NULL;
      }
    }
    return $prices;
  }

  /**
   * Get the current price of each enabled day of the event's member types.
   *
   * A day with no price is free, so its price is given as 0.
   *
   * @param object $memberTypes
   *   The member types, as returned by ConregOptions::memberTypes().
   *
   * @return array
   *   Prices keyed by member type code, then by day code.
   */
  public function getCurrentDayPrices(object $memberTypes): array {
    $prices = [];
    foreach ($memberTypes->types as $type => $memberType) {
      foreach ($memberType->days ?? [] as $day => $dayOptions) {
        $prices[$type][$day] = is_numeric($dayOptions->price ?? NULL) ? $dayOptions->price : '0';
      }
    }
    return $prices;
  }

  /**
   * Get the labels of enabled days the plan has no price for.
   *
   * @param \Drupal\conreg\Entity\RatePlan $plan
   *   The rate plan.
   * @param object $memberTypes
   *   The member types, as returned by ConregOptions::memberTypes().
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[][]
   *   Labels such as "Dealer, Friday", keyed by member type code then day code.
   */
  public function getMissingDayPrices(RatePlan $plan, object $memberTypes): array {
    $names = $this->getMemberTypeNames($memberTypes);
    $dayNames = $this->getDayNames($memberTypes);
    $missing = [];
    foreach ($this->getPlanDayPrices($plan, $memberTypes) as $type => $days) {
      foreach ($days as $day => $price) {
        if ($price === NULL) {
          $missing[$type][$day] = $this->dayLabel($names[$type], $dayNames[$type][$day]);
        }
      }
    }
    return $missing;
  }

  /**
   * Get the name of each of the event's member types.
   *
   * @param object $memberTypes
   *   The member types, as returned by ConregOptions::memberTypes().
   *
   * @return string[]
   *   Names keyed by member type code. Member types with no name are given
   *   their code.
   */
  public function getMemberTypeNames(object $memberTypes): array {
    $names = [];
    foreach ($memberTypes->types as $type => $memberType) {
      $names[$type] = (string) ($memberType->name ?? $type);
    }
    return $names;
  }

  /**
   * Get the name of each enabled day of the event's member types.
   *
   * @param object $memberTypes
   *   The member types, as returned by ConregOptions::memberTypes().
   *
   * @return string[][]
   *   Names keyed by member type code, then by day code. Days with no name are
   *   given their code.
   */
  public function getDayNames(object $memberTypes): array {
    $names = [];
    foreach ($memberTypes->types as $type => $memberType) {
      foreach ($memberType->days ?? [] as $day => $dayOptions) {
        $names[$type][$day] = (string) ($dayOptions->name ?? $day);
      }
    }
    return $names;
  }

  /**
   * Label a member type's day, e.g. "Dealer, Friday".
   *
   * @param string $typeName
   *   The member type name.
   * @param string $dayName
   *   The day name.
   */
  public function dayLabel(string $typeName, string $dayName): TranslatableMarkup {
    return $this->t('@type, @day', ['@type' => $typeName, '@day' => $dayName]);
  }

  /**
   * Label a day's row that sits under its member type's row in a table.
   *
   * The member type is only hidden visually, so the row still makes sense to
   * screen readers.
   *
   * @param string $typeName
   *   The member type name.
   * @param string $dayName
   *   The day name.
   */
  public function dayRowLabel(string $typeName, string $dayName): TranslatableMarkup {
    return $this->t('<span class="visually-hidden">@type, </span>@day', [
      '@type' => $typeName,
      '@day' => $dayName,
    ]);
  }

  /**
   * Check whether applying a plan would change any member type or day price.
   *
   * @param \Drupal\conreg\Entity\RatePlan $plan
   *   The rate plan.
   * @param object $memberTypes
   *   The member types, as returned by ConregOptions::memberTypes().
   */
  public function hasPriceChanges(RatePlan $plan, object $memberTypes): bool {
    foreach ($this->getPlanPrices($plan, $memberTypes) as $type => $price) {
      if (!$this->pricesEqual($memberTypes->types[$type]->price ?? NULL, $price)) {
        return TRUE;
      }
    }
    $current = $this->getCurrentDayPrices($memberTypes);
    foreach ($this->getPlanDayPrices($plan, $memberTypes) as $type => $days) {
      foreach ($days as $day => $price) {
        if (!$this->pricesEqual($current[$type][$day], $price)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Number of days from today (in the convention timezone) to a date.
   *
   * @param string $date
   *   The date in Y-m-d format.
   *
   * @return int
   *   Days until the date. Negative if the date is in the past.
   */
  public function daysUntil(string $date): int {
    $timezone = $this->getTimezone();
    $today = (new \DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($timezone)
      ->setTime(0, 0);
    $planned = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    return (int) $today->diff($planned)->format('%r%a');
  }

  /**
   * Describe a planned date relative to today, e.g. "in 3 days".
   *
   * @param string $date
   *   The date in Y-m-d format.
   */
  public function relativeDate(string $date): TranslatableMarkup {
    $days = $this->daysUntil($date);
    if ($days === 0) {
      return $this->t('Today');
    }
    if ($days > 0) {
      return $this->formatPlural($days, 'In 1 day', 'In @count days');
    }
    return $this->formatPlural(-$days, '1 day overdue', '@count days overdue');
  }

  /**
   * Format a planned date for display, e.g. "Sat, 1 November 2026".
   *
   * @param string $date
   *   The date in Y-m-d format.
   * @param bool $withWeekday
   *   Whether to include the abbreviated day of the week. Leave it out where
   *   the date is read by screen readers out of context, e.g. in labels.
   */
  public function formatPlannedDate(string $date, bool $withWeekday = TRUE): string {
    $timezone = $this->getTimezone();
    $planned = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    return $this->dateFormatter->format($planned->getTimestamp(), 'custom', $withWeekday ? 'D, j F Y' : 'j F Y', $timezone->getName());
  }

  /**
   * Format a timestamp for display in the convention timezone.
   */
  public function formatTimestamp(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'medium', '', $this->getTimezone()->getName());
  }

  /**
   * Check whether two prices are the same amount.
   */
  public function pricesEqual(mixed $a, mixed $b): bool {
    if (!is_numeric($a) || !is_numeric($b)) {
      return (string) $a === (string) $b;
    }
    return round((float) $a, 2) === round((float) $b, 2);
  }

  /**
   * Format a price for display.
   *
   * @param int $eid
   *   The event ID, used to find the currency symbol.
   * @param mixed $price
   *   The price, or NULL if not set.
   */
  public function formatPrice(int $eid, mixed $price): string|TranslatableMarkup {
    if (!is_numeric($price)) {
      return $this->t('Not set');
    }
    return $this->getCurrencySymbol($eid) . number_format((float) $price, 2);
  }

  /**
   * Get the currency symbol prices are shown with, e.g. "€".
   *
   * @param int $eid
   *   The event ID.
   */
  public function getCurrencySymbol(int $eid): string {
    return (string) ($this->configFactory->get('conreg.settings.' . $eid)->get('payments.symbol') ?? '');
  }

  /**
   * Format the difference between two prices, e.g. "+€5.00" or "−€2.50".
   *
   * Decreases use a true minus sign (U+2212), not a hyphen.
   *
   * Returns an empty string if the prices are the same or either is not set.
   *
   * @param int $eid
   *   The event ID, used to find the currency symbol.
   * @param mixed $from
   *   The original price, or NULL if not set.
   * @param mixed $to
   *   The new price, or NULL if not set.
   */
  public function formatPriceChange(int $eid, mixed $from, mixed $to): string {
    if (!is_numeric($from) || !is_numeric($to)) {
      return '';
    }
    $difference = round((float) $to - (float) $from, 2);
    if ($difference == 0) {
      return '';
    }
    return ($difference > 0 ? '+' : "\u{2212}") . $this->formatPrice($eid, abs($difference));
  }

  /**
   * Apply a rate plan, setting each member type and day price to the plan's.
   *
   * Access control only allows applying plans with a price for every member
   * type and enabled day that would change a price, so this doesn't check
   * again.
   *
   * @param \Drupal\conreg\Entity\RatePlan $plan
   *   The rate plan.
   *
   * @throws \Drupal\conreg\Exception\RatePlanApplyException
   *   If a plan for the same event is being applied by another request, or the
   *   plan has been deleted or has already been applied.
   */
  public function apply(RatePlan $plan): void {
    // Only one plan per event can be applied at a time, so each plan records
    // the prices left by the one before it, and if a plan is applied twice at
    // once the prices only change once. A plan's event never changes, so the
    // copy passed in can be trusted for it.
    $eid = $plan->getEventId();
    $lockName = 'conreg_rate_plan_apply:' . $eid;
    if (!$this->lock->acquire($lockName)) {
      $reason = $this->t('Another rate plan for this event is being applied.');
      throw new RatePlanApplyException($reason);
    }
    try {
      // Check the saved plan, not the one passed in, in case another request
      // applied or deleted it before the lock was acquired.
      $plan = $this->entityTypeManager->getStorage('conreg_rate_plan')->loadUnchanged($plan->id());
      if (!$plan) {
        $reason = $this->t('The rate plan has been deleted.');
        throw new RatePlanApplyException($reason);
      }
      if ($plan->isApplied()) {
        $reason = $this->t('The rate plan has already been applied.');
        throw new RatePlanApplyException($reason);
      }

      // Only the prices are changed, directly in the saved config, so nothing
      // else about the member types is overwritten.
      $config = $this->configFactory->getEditable('conreg.settings.' . $eid);

      // Tidy away prices for member types deleted since the plan was saved, so
      // the applied plan records only the prices it changed.
      $plan->setPrices(array_intersect_key($plan->getPrices(), $config->get('member.types') ?? []));

      // Likewise for days disabled since, as the plan never enables days.
      $dayPrices = [];
      foreach ($plan->getDayPrices() as $type => $days) {
        $enabled = $config->get("member.types.$type.days") ?? [];
        if ($days = array_intersect_key($days, $enabled)) {
          $dayPrices[$type] = $days;
        }
      }
      $plan->setDayPrices($dayPrices);

      // Record the names as they are now, so the applied plan isn't changed by
      // later renames.
      $names = [];
      foreach (array_keys($plan->getPrices()) as $type) {
        $names[$type] = (string) ($config->get("member.types.$type.name") ?? $type);
      }
      $eventDayNames = $this->conregOptions->days($eid);
      $dayNames = [];
      foreach ($dayPrices as $type => $days) {
        foreach (array_keys($days) as $day) {
          $dayNames[$type][$day] = $eventDayNames[$day] ?? (string) $day;
        }
      }
      $plan->setNames($names, $dayNames);

      $previous = [];
      foreach ($plan->getPrices() as $type => $price) {
        $current = $config->get("member.types.$type.price");
        $previous[$type] = is_numeric($current) ? $current : NULL;
        $config->set("member.types.$type.price", $this->normalizePrice($price));
      }
      $previousDays = [];
      foreach ($dayPrices as $type => $days) {
        foreach ($days as $day => $price) {
          // A day with no price is free, as in getCurrentDayPrices().
          $current = $config->get("member.types.$type.days.$day.price");
          $previousDays[$type][$day] = is_numeric($current) ? $current : '0';
          $config->set("member.types.$type.days.$day.price", $this->normalizePrice($price));
        }
      }

      // Marking the plan applied and changing the prices happen in one
      // transaction, so if either fails neither takes effect. The plan is
      // saved first, so if that fails the config hasn't been touched.
      $transaction = $this->connection->startTransaction();
      try {
        $plan->markApplied($previous, $previousDays, (int) $this->currentUser->id(), $this->time->getCurrentTime())->save();
        $config->save();
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      // Commit before invalidating caches, so they rebuild from the new prices.
      unset($transaction);
    }
    finally {
      $this->lock->release($lockName);
    }

    // The member types cache is tagged with the event's type tag, so this
    // also clears it.
    $this->cacheTagsInvalidator->invalidateTags([
      'event:' . $eid . ':type',
      'event:' . $eid . ':registration',
    ]);
  }

  /**
   * Convert a stored decimal price to the format used in member type config.
   *
   * Whole amounts are stored without decimals ("50"), matching prices entered
   * on the member types form.
   */
  protected function normalizePrice(string|int|float $price): string {
    $rounded = round((float) $price, 2);
    if ($rounded == floor($rounded)) {
      return (string) (int) $rounded;
    }
    return number_format($rounded, 2, '.', '');
  }

}
