<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

use Drupal\conreg\Payment;

/**
 * The outcome of recomputing a payment's prices at checkout time.
 */
final readonly class PricingRecomputeResult {

  /**
   * Constructs a PricingRecomputeResult.
   *
   * @param \Drupal\conreg\Payment $payment
   *   The payment, with any drifted payment lines already updated/saved.
   * @param bool $drifted
   *   Whether any payment line's amount differed from the freshly
   *   calculated price and had to be corrected.
   */
  public function __construct(
    public Payment $payment,
    public bool $drifted,
  ) {}

}
