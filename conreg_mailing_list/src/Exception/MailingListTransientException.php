<?php

namespace Drupal\conreg_mailing_list\Exception;

/**
 * Thrown for a retryable mailing list failure (timeout, rate limit, 5xx).
 *
 * The queue worker lets this propagate so Drupal requeues the item.
 */
class MailingListTransientException extends MailingListException {
}
