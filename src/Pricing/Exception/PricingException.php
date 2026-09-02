<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing\Exception;

/**
 * May be thrown by a pricing plugin when it cannot price a member at all.
 *
 * None of the shipped plugins throw this - an unresolvable member type is
 * priced at 0, matching the original getMemberPrice()'s graceful behavior,
 * and `PricingService::recomputeForPayment()` checks resolvability up front
 * (via `PricingContext::hasType()`) rather than relying on a thrown
 * exception, so a stale/deleted type doesn't block payment for the rest of
 * the group. This class exists for a submodule's own pricing plugin that
 * has stricter validation needs and genuinely cannot degrade gracefully.
 */
class PricingException extends \RuntimeException {

}
