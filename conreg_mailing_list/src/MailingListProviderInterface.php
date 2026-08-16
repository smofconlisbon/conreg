<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list;

/**
 * Interface for mailing_list_provider plugins.
 */
interface MailingListProviderInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Fetch the lists available from the provider.
   *
   * Called when rendering the option-to-list mapping config form, so the value
   * is the provider's own list identifier and the label is human readable.
   *
   * @return array
   *   Map of provider list ID => human readable label.
   *
   * @throws \Drupal\conreg_mailing_list\Exception\MailingListException
   *   When the lists could not be fetched (bad credentials, API down, etc.).
   *   The config form catches this and degrades gracefully.
   */
  public function getLists(): array;

  /**
   * Subscribe an email address to a list.
   *
   * Implementations should upsert by email so repeated calls for the same
   * address are harmless. Must not resubscribe someone the provider records as
   * unsubscribed.
   *
   * @param string $email
   *   The subscriber's email address.
   * @param string $listId
   *   The provider list identifier to add the subscriber to.
   * @param array $fields
   *   Additional subscriber fields. For v1 this contains at most 'name'.
   *
   * @throws \Drupal\conreg_mailing_list\Exception\MailingListTransientException
   *   On a retryable failure (timeout, rate limit, 5xx). The queue retries.
   * @throws \Drupal\conreg_mailing_list\Exception\MailingListPermanentException
   *   On a non-retryable failure (invalid address). The item is dropped.
   */
  public function subscribe(string $email, string $listId, array $fields): void;

}
