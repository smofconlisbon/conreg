<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

/**
 * What one member_pricing_rule plugin contributes when pricing a member.
 */
final readonly class MemberPriceContribution {

  /**
   * Constructs a MemberPriceContribution.
   *
   * @param \Drupal\conreg\Pricing\PriceLine[] $lines
   *   The price line(s) contributed by this rule.
   * @param string|null $days
   *   The pipe-delimited selected day codes, if this rule resolves day
   *   selection (only one rule is expected to set this in practice).
   * @param string|null $daysDesc
   *   The human-readable day description, if this rule resolves it.
   */
  public function __construct(
    public array $lines,
    public ?string $days = NULL,
    public ?string $daysDesc = NULL,
  ) {}

}
