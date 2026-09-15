<?php

namespace Drupal\conreg\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Cron hook implementations for conreg.
 *
 * Kept separate from ConregHooks so this class - and the services it
 * depends on - only need to be loaded when cron actually runs, not on
 * every user-facing request.
 */
class CronHooks {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Deletes old badge label print jobs so the job table (which now
   * carries a base64-encoded rendered image per row) doesn't grow
   * without bound. Deletion is based on `changed`, regardless of
   * status - a job that's sat "pending" for weeks isn't going to print.
   */
  #[Hook('cron')]
  public function cron(): void {
    $config = $this->configFactory->get('conreg.label_printing.settings');
    $retentionDays = (int) ($config->get('retention_days') ?: 30);
    $cutoff = $this->time->getRequestTime() - ($retentionDays * 86400);

    $jobStorage = $this->entityTypeManager->getStorage('conreg_print_job');
    $ids = $jobStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('changed', $cutoff, '<')
      ->execute();

    foreach (array_chunk($ids, 50) as $chunk) {
      $jobStorage->delete($jobStorage->loadMultiple($chunk));
    }
  }

}
