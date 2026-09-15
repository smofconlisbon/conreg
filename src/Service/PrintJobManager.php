<?php

namespace Drupal\conreg\Service;

use Drupal\conreg\ConregConfig;
use Drupal\conreg\Entity\PrintJob;
use Drupal\conreg\Trait\ShowBadgeNumberTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Creates badge label print jobs for members.
 */
class PrintJobManager {

  use ShowBadgeNumberTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MemberStorage $memberStorage,
    protected ConregOptions $conregOptions,
    protected ConfigFactoryInterface $configFactory,
    protected LabelRenderer $labelRenderer,
  ) {}

  /**
   * Creates a pending print job for a member.
   *
   * Snapshots the member's current badge name, formatted badge number
   * (badge type prefix plus zero-padded number, matching the check-in
   * table), and days attending onto the job, so it stays correct even
   * if the member record changes before the job is printed.
   *
   * @param int $mid
   *   The member ID to print a label for.
   * @param string $printerMachineName
   *   The target printer's machine name, e.g. "bilbo_baggins".
   *
   * @return \Drupal\conreg\Entity\PrintJob
   *   The newly created, pending print job.
   *
   * @throws \InvalidArgumentException
   *   If the member or printer cannot be found.
   */
  public function createJob(int $mid, string $printerMachineName): PrintJob {
    $member = $this->memberStorage->load(['mid' => $mid]);
    if (!$member) {
      throw new \InvalidArgumentException("Unknown member ID: $mid");
    }

    $eid = (int) $member['eid'];

    $printerStorage = $this->entityTypeManager->getStorage('conreg_printer');
    $printers = $printerStorage->loadByProperties(['eid' => $eid, 'machine_name' => $printerMachineName]);
    $printer = reset($printers);
    if (!$printer) {
      throw new \InvalidArgumentException("Unknown printer \"$printerMachineName\" for event $eid.");
    }

    $config = ConregConfig::getConfig($eid);

    $daysAttending = '';
    if (!empty($member['days'])) {
      $dayOptions = $this->conregOptions->days($eid);
      $dayDescriptions = [];
      foreach (explode('|', $member['days']) as $day) {
        $dayDescriptions[] = $dayOptions[$day] ?? $day;
      }
      $daysAttending = implode(', ', $dayDescriptions);
    }

    $fields = [
      'badge_name' => $member['badge_name'],
      'member_number' => $this->showBadgeNumber($member, $config),
      'days_attending' => $daysAttending,
      'badge_type' => trim($member['badge_type'] ?? ''),
    ];

    $jobStorage = $this->entityTypeManager->getStorage('conreg_print_job');
    /** @var \Drupal\conreg\Entity\PrintJob $job */
    $job = $jobStorage->create([
      'eid' => $eid,
      'mid' => $mid,
      'member_name' => $fields['badge_name'],
      'member_number' => $fields['member_number'],
      'days_attending' => $fields['days_attending'],
      'badge_type' => $fields['badge_type'],
      'printer' => $printer->id(),
      'status' => 'pending',
      'image_data' => $this->renderLabelImage($fields),
    ]);
    $job->save();

    return $job;
  }

  /**
   * Renders the label image for a job's field snapshot.
   *
   * Rendering happens once, here, at job-creation time - matching this
   * method's existing "snapshot now, stays correct even if things
   * change later" philosophy, and avoiding re-rendering on every poll
   * or retry of the same job. Returns NULL (rather than throwing) when
   * no label size is configured yet, so check-in isn't blocked by
   * incomplete Label Printing Settings configuration.
   */
  protected function renderLabelImage(array $fields): ?string {
    $config = $this->configFactory->get('conreg.label_printing.settings');
    $labelSizeId = $config->get('label_size');
    if (!$labelSizeId) {
      return NULL;
    }

    /** @var \Drupal\conreg\Entity\LabelSize|null $labelSize */
    $labelSize = $this->entityTypeManager->getStorage('conreg_label_size')->load($labelSizeId);
    if (!$labelSize) {
      return NULL;
    }

    $png = $this->labelRenderer->render(
      $fields,
      $labelSize,
      $config->get('field_positions') ?: [],
      (int) ($config->get('name_lines') ?: 2),
    );

    return base64_encode($png);
  }

  /**
   * Queues a "test print" job from an already-rendered preview image.
   *
   * Used by the Label Printing Settings page's "Test print" button,
   * which sends exactly what was just shown in Preview - nothing is
   * re-rendered here, so what you previewed is exactly what prints.
   * No real member is involved, so `mid` is left unset and `is_test`
   * marks the row so it can be told apart from real check-in jobs.
   *
   * @param array $fields
   *   The sample field values the preview was generated from (stored
   *   on the job for reference; not used to render anything here).
   * @param int $printerId
   *   The `conreg_printer` entity ID to queue the job against.
   * @param string $imageDataBase64
   *   The already-rendered (unrotated) label PNG, base64-encoded.
   *
   * @return \Drupal\conreg\Entity\PrintJob
   *   The newly created, pending test print job.
   *
   * @throws \InvalidArgumentException
   *   If the printer cannot be found.
   */
  public function createTestJob(array $fields, int $printerId, string $imageDataBase64): PrintJob {
    $printerStorage = $this->entityTypeManager->getStorage('conreg_printer');
    /** @var \Drupal\conreg\Entity\Printer|null $printer */
    $printer = $printerStorage->load($printerId);
    if (!$printer) {
      throw new \InvalidArgumentException("Unknown printer ID: $printerId");
    }

    $jobStorage = $this->entityTypeManager->getStorage('conreg_print_job');
    /** @var \Drupal\conreg\Entity\PrintJob $job */
    $job = $jobStorage->create([
      'eid' => (int) $printer->get('eid')->value,
      'is_test' => TRUE,
      'member_name' => $fields['badge_name'] ?? '',
      'member_number' => $fields['member_number'] ?? '',
      'days_attending' => $fields['days_attending'] ?? '',
      'badge_type' => $fields['badge_type'] ?? '',
      'printer' => $printer->id(),
      'status' => 'pending',
      'image_data' => $imageDataBase64,
    ]);
    $job->save();

    return $job;
  }

  /**
   * Loads all printers configured for an event.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return \Drupal\conreg\Entity\Printer[]
   *   Printer entities for the event, keyed by entity ID.
   */
  public function getPrintersForEvent(int $eid): array {
    $printerStorage = $this->entityTypeManager->getStorage('conreg_printer');
    return $printerStorage->loadByProperties(['eid' => $eid]);
  }

}
