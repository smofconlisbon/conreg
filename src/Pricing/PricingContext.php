<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

use Drupal\Core\Config\ImmutableConfig;

/**
 * Shared, whole-registration context passed to every pricing plugin.
 */
final readonly class PricingContext {

  /**
   * Constructs a PricingContext.
   *
   * @param int $eid
   *   The event ID.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The event's configuration.
   * @param array $types
   *   The `ConregOptions::memberTypes($eid, $config)->types` map, keyed by
   *   type code to a `stdClass`. Left as the untyped array/stdClass graph
   *   it already is (submodules extend it with their own properties),
   *   rather than introduced into a strict typed class.
   * @param string $symbol
   *   The currency symbol.
   * @param bool $discountEnabled
   *   Whether the "every Nth member free" discount is enabled.
   * @param int $discountFreeEvery
   *   The discount's configured "every N" threshold.
   * @param \Drupal\conreg\Pricing\AddOnSelection[] $globalAddOns
   *   Sitewide (not per-member) add-on selections, keyed by add-on ID.
   */
  public function __construct(
    public int $eid,
    public ImmutableConfig $config,
    public array $types,
    public string $symbol,
    public bool $discountEnabled,
    public int $discountFreeEvery,
    public array $globalAddOns = [],
  ) {}

  /**
   * The base price for a member type, or NULL if the type is unknown.
   */
  public function typePrice(string $memberType): ?float {
    return isset($this->types[$memberType]) ? (float) $this->types[$memberType]->price : NULL;
  }

  /**
   * The member type's default `days` value, or an empty string.
   */
  public function typeDefaultDays(string $memberType): string {
    return isset($this->types[$memberType]) ? trim((string) $this->types[$memberType]->defaultDays) : '';
  }

  /**
   * The member type's day-price map (day code => stdClass{price, name}).
   */
  public function typeDays(string $memberType): ?array {
    return $this->types[$memberType]->days ?? NULL;
  }

  /**
   * Whether a member type code exists.
   */
  public function hasType(string $memberType): bool {
    return isset($this->types[$memberType]);
  }

  /**
   * Builds a context from registration/check-in form values.
   *
   * @param int $eid
   *   The event ID.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The event's configuration.
   * @param array $types
   *   `ConregOptions::memberTypes($eid, $config)->types`.
   * @param array $form_values
   *   The full form values array.
   */
  public static function fromFormValues(int $eid, ImmutableConfig $config, array $types, array $form_values): self {
    $globalAddOns = [];
    foreach (($config->get('add-ons') ?? []) as $addOnId => $addOnVals) {
      $addon = $addOnVals['addon'] ?? [];
      if (($addon['active'] ?? 0) == 1 && !empty($addon['global'])) {
        $isFree = !empty($addon['free']);
        $values = $form_values['payment']['global_add_on'][$addOnId] ?? [];
        if (!$isFree && !empty($values['option'])) {
          $globalAddOns[$addOnId] = new AddOnSelection($addOnId, $values['option'], 0.0, TRUE);
        }
        elseif ($isFree && !empty($values['free_amount'])) {
          $globalAddOns[$addOnId] = new AddOnSelection($addOnId, NULL, (float) $values['free_amount'], TRUE);
        }
      }
    }

    return new self(
      $eid,
      $config,
      $types,
      (string) $config->get('payments.symbol'),
      (bool) $config->get('discount.enable'),
      (int) $config->get('discount.free_every'),
      $globalAddOns,
    );
  }

}
