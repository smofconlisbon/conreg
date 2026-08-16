<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list_test;

/**
 * Records subscribe() calls made through FakeProvider, and controls failures.
 */
final class FakeProviderCallRecorder {

  /**
   * Recorded subscribe() calls, in order.
   *
   * @var array<int, array{email: string, listId: string, fields: array}>
   */
  public array $calls = [];

  /**
   * The failure mode subscribe() should simulate.
   *
   * NULL, 'transient', or 'permanent'.
   */
  public ?string $subscribeFailure = NULL;

  /**
   * Whether getLists() should simulate a provider failure.
   */
  public bool $getListsFailure = FALSE;

  /**
   * Records a call and returns the configured failure mode, if any.
   */
  public function recordCall(string $email, string $listId, array $fields): ?string {
    $this->calls[] = ['email' => $email, 'listId' => $listId, 'fields' => $fields];
    return $this->subscribeFailure;
  }

}
