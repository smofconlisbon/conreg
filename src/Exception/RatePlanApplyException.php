<?php

declare(strict_types=1);

namespace Drupal\conreg\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Thrown when a rate plan can't be applied.
 *
 * Only thrown in unlikely cases, such as a plan being applied twice at once.
 * The message is untranslated, for logs; the reason is translated, for showing
 * to the administrator.
 */
class RatePlanApplyException extends \RuntimeException {

  /**
   * Constructs a RatePlanApplyException.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $reason
   *   Why the plan couldn't be applied.
   */
  public function __construct(protected TranslatableMarkup $reason) {
    parent::__construct($reason->getUntranslatedString());
  }

  /**
   * Gets why the plan couldn't be applied, translated.
   */
  public function getReason(): TranslatableMarkup {
    return $this->reason;
  }

}
