<?php

namespace Drupal\conreg\Service;

use Drupal\conreg\ConregConfig;
use Drupal\conreg\Entity\PrintJob;
use Drupal\conreg\Trait\ShowBadgeNumberTrait;
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

    $jobStorage = $this->entityTypeManager->getStorage('conreg_print_job');
    /** @var \Drupal\conreg\Entity\PrintJob $job */
    $job = $jobStorage->create([
      'eid' => $eid,
      'mid' => $mid,
      'member_name' => $member['badge_name'],
      'member_number' => $this->showBadgeNumber($member, $config),
      'days_attending' => $daysAttending,
      'printer' => $printer->id(),
      'status' => 'pending',
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
