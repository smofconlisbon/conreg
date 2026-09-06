<?php

namespace Drupal\conreg\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the badge label print job exchange API.
 */
class PrintJobController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the next print job for an agent to process.
   *
   * Claims the oldest pending job for the given event and printer, so
   * that two agents polling on behalf of the same printer can't both
   * receive it.
   */
  public function next(Request $request, int $eid): JsonResponse {
    $printerName = $request->query->get('printer');
    if (!$printerName) {
      return new JsonResponse(['error' => 'Missing required "printer" query parameter.'], 400);
    }

    $printerStorage = $this->entityTypeManager()->getStorage('conreg_printer');
    $printers = $printerStorage->loadByProperties(['eid' => $eid, 'name' => $printerName]);
    $printer = reset($printers);
    if (!$printer) {
      return new JsonResponse(['error' => 'Unknown printer for this event.'], 404);
    }

    $jobId = $this->claimNextJob($eid, (int) $printer->id());
    if (!$jobId) {
      return new JsonResponse(NULL, 204);
    }

    $jobStorage = $this->entityTypeManager()->getStorage('conreg_print_job');
    /** @var \Drupal\conreg\Entity\PrintJob $job */
    $job = $jobStorage->load($jobId);

    return new JsonResponse([
      'job_id' => (string) $job->id(),
      'member_name' => $job->get('member_name')->value,
      'member_number' => $job->get('member_number')->value,
      'days_attending' => $job->get('days_attending')->value,
    ]);
  }

  /**
   * Atomically claims the oldest pending job for an event/printer pair.
   *
   * Entity API's query system can find candidates, but a find-then-save
   * from PHP isn't atomic across two concurrently polling agents. The
   * claiming UPDATE re-checks status = 'pending', so a second agent
   * racing the same printer can't also claim the row this SELECT found.
   *
   * @return int|null
   *   The claimed job ID, or NULL if no job was pending.
   */
  protected function claimNextJob(int $eid, int $printerId): ?int {
    $table = $this->entityTypeManager()->getDefinition('conreg_print_job')->getBaseTable();

    $transaction = $this->database->startTransaction();
    $jobId = $this->database->select($table, 'pj')
      ->fields('pj', ['id'])
      ->condition('eid', $eid)
      ->condition('printer', $printerId)
      ->condition('status', 'pending')
      ->orderBy('created', 'ASC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($jobId) {
      $claimed = $this->database->update($table)
        ->fields([
          'status' => 'claimed',
          'changed' => $this->time->getRequestTime(),
        ])
        ->condition('id', $jobId)
        ->condition('status', 'pending')
        ->execute();
      if (!$claimed) {
        // Lost the race to another agent between the SELECT and the UPDATE.
        $jobId = NULL;
      }
    }
    unset($transaction);

    return $jobId ? (int) $jobId : NULL;
  }

  /**
   * Accepts a print result from an agent and updates the job status.
   */
  public function result(Request $request, int $eid, int $id): JsonResponse {
    $jobStorage = $this->entityTypeManager()->getStorage('conreg_print_job');
    /** @var \Drupal\conreg\Entity\PrintJob|null $job */
    $job = $jobStorage->load($id);

    if (!$job || (int) $job->get('eid')->value !== $eid) {
      return new JsonResponse(['error' => 'Unknown print job for this event.'], 404);
    }

    $data = json_decode($request->getContent(), TRUE) ?? [];
    $status = $data['status'] ?? 'unknown';
    $message = $data['message'] ?? '';

    $job->set('status', $status);
    $job->set('message', $message);
    $job->save();

    $this->getLogger('conreg')->notice(
      'Print job result: eid=@eid id=@id status=@status message=@message',
      ['@eid' => $eid, '@id' => $id, '@status' => $status, '@message' => $message]
    );

    return new JsonResponse(['received' => TRUE]);
  }

}
