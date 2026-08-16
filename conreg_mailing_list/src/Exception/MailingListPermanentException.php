<?php

namespace Drupal\conreg_mailing_list\Exception;

/**
 * Thrown for a non-retryable mailing list failure (e.g. invalid address).
 *
 * The queue worker logs this and drops the item so one bad address cannot
 * wedge the queue.
 */
class MailingListPermanentException extends MailingListException {
}
