<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

/**
 * One member's (or the registration's) selection for a single add-on.
 */
final readonly class AddOnSelection {

  /**
   * Constructs an AddOnSelection.
   *
   * @param string $addOnId
   *   The add-on machine name (config key under 'add-ons').
   * @param string|null $option
   *   The selected dropdown option, or NULL for a free-form add-on.
   * @param float $freeAmount
   *   The amount entered for a free-form ("pay what you want") add-on.
   * @param bool $isGlobal
   *   Whether this add-on is sitewide (one selection per registration)
   *   rather than per-member.
   */
  public function __construct(
    public string $addOnId,
    public ?string $option,
    public float $freeAmount,
    public bool $isGlobal,
  ) {}

}
