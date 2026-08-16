<?php

namespace Drupal\conreg_mailing_list\Exception;

/**
 * Base class for all mailing list provider failures.
 *
 * Callers that only care "did the provider fail" (e.g. the config form's
 * graceful degradation) catch this. Callers that must distinguish retryable
 * from permanent failures (the queue worker) catch the subclasses.
 */
class MailingListException extends \RuntimeException {
}
