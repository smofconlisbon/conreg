<?php

namespace Drupal\conreg\Service;

use Drupal\conreg\ConregOptions;
use Drupal\conreg\Entity\PrintJob;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Creates badge label print jobs for members.
 */
class PrintJobManager {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MemberStorage $memberStorage,
  ) {}

  /**
   * Creates a pending print job for a member.
   *
   * Snapshots the member's current badge name, member number, and days
   * attending onto the job, so it stays correct even if the member
   * record changes before the job is printed.
   *
   * @param int $mid
   *   The member ID to print a label for.
   * @param string $printerName
   *   The target printer's name, e.g. "Bilbo Baggins".
   *
   * @return \Drupal\conreg\Entity\PrintJob
   *   The newly created, pending print job.
   *
   * @throws \InvalidArgumentException
   *   If the member or printer cannot be found.
   */
  public function createJob(int $mid, string $printerName): PrintJob {
    $member = $this->memberStorage->load(['mid' => $mid]);
    if (!$member) {
      throw new \InvalidArgumentException("Unknown member ID: $mid");
    }

    $eid = (int) $member['eid'];

    $printerStorage = $this->entityTypeManager->getStorage('conreg_printer');
    $printers = $printerStorage->loadByProperties(['eid' => $eid, 'name' => $printerName]);
    $printer = reset($printers);
    if (!$printer) {
      throw new \InvalidArgumentException("Unknown printer \"$printerName\" for event $eid.");
    }

    $daysAttending = '';
    if (!empty($member['days'])) {
      $dayOptions = ConregOptions::days($eid);
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
      'member_number' => $member['member_no'],
      'days_attending' => $daysAttending,
      'printer' => $printer->id(),
      'status' => 'pending',
    ]);
    $job->save();

    return $job;
  }

}
