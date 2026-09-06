<?php

namespace Drupal\conreg\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the badge label print job exchange API.
 */
class PrintJobController extends ControllerBase {

  /**
   * How long a job may sit "claimed" with no result before it's abandoned.
   *
   * E.g. the agent crashed or lost its network connection - once this
   * many seconds pass with no result, the job is offered to the next
   * poll again.
   */
  protected const STALE_CLAIM_TIMEOUT_SECONDS = 300;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    #[Autowire(service: 'key.repository')]
    protected KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * Resolves the configured print-job API key's value for an event.
   */
  protected function resolvePrintApiKey(int $eid): string {
    $keyId = $this->config('conreg.settings.' . $eid)->get('checkin.print_api_key');
    if (empty($keyId)) {
      return '';
    }
    try {
      return trim((string) ($this->keyRepository->getKey($keyId)?->getKeyValue() ?? ''));
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Checks the request's Authorization header against the event's key.
   *
   * Refuses by default: if no key is configured for the event, this
   * always returns FALSE - there is no open fallback.
   */
  protected function isAuthorized(Request $request, int $eid): bool {
    $configuredKey = $this->resolvePrintApiKey($eid);
    if ($configuredKey === '') {
      return FALSE;
    }
    $header = (string) $request->headers->get('Authorization', '');
    if (!str_starts_with($header, 'Bearer ')) {
      return FALSE;
    }
    return hash_equals($configuredKey, substr($header, strlen('Bearer ')));
  }

  /**
   * Returns the next print job for an agent to process.
   *
   * Claims the oldest pending job for the given event and printer, so
   * that two agents polling on behalf of the same printer can't both
   * receive it.
   */
  public function next(Request $request, int $eid): JsonResponse {
    if (!$this->isAuthorized($request, $eid)) {
      return new JsonResponse(['error' => 'Missing or invalid API key.'], 401);
    }

    $printerMachineName = $request->query->get('printer');
    if (!$printerMachineName) {
      return new JsonResponse(['error' => 'Missing required "printer" query parameter.'], 400);
    }

    $printerStorage = $this->entityTypeManager()->getStorage('conreg_printer');
    $printers = $printerStorage->loadByProperties(['eid' => $eid, 'machine_name' => $printerMachineName]);
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
   * Atomically claims the oldest pending or abandoned job for a printer.
   *
   * Entity API's query system can find candidates, but a find-then-save
   * from PHP isn't atomic across two concurrently polling agents. The
   * claiming UPDATE re-checks the same eligibility condition, so a second
   * agent racing the same printer can't also claim the row this SELECT
   * found.
   *
   * A job claimed longer than STALE_CLAIM_TIMEOUT_SECONDS ago with no
   * result is treated as abandoned (the agent that claimed it presumably
   * crashed or lost connectivity) and becomes eligible again, so a job
   * can never be stuck in "claimed" forever with nothing else happening
   * to it.
   *
   * @return int|null
   *   The claimed job ID, or NULL if no job was pending or abandoned.
   */
  protected function claimNextJob(int $eid, int $printerId): ?int {
    $table = $this->entityTypeManager()->getDefinition('conreg_print_job')->getBaseTable();

    $transaction = $this->database->startTransaction();
    $jobId = $this->database->select($table, 'pj')
      ->fields('pj', ['id'])
      ->condition('eid', $eid)
      ->condition('printer', $printerId)
      ->condition($this->eligibleForClaimCondition())
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
        ->condition($this->eligibleForClaimCondition())
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
   * Builds the "pending, or abandoned" condition used to find/claim a job.
   *
   * A fresh Condition object is built on every call rather than shared,
   * since the same instance shouldn't be attached to two different query
   * builders.
   */
  protected function eligibleForClaimCondition(): Condition {
    $staleBefore = $this->time->getRequestTime() - self::STALE_CLAIM_TIMEOUT_SECONDS;

    return (new Condition('OR'))
      ->condition('status', 'pending')
      ->condition((new Condition('AND'))
        ->condition('status', 'claimed')
        ->condition('changed', $staleBefore, '<')
      );
  }

  /**
   * Accepts a print result from an agent and updates the job status.
   */
  public function result(Request $request, int $eid, int $id): JsonResponse {
    if (!$this->isAuthorized($request, $eid)) {
      return new JsonResponse(['error' => 'Missing or invalid API key.'], 401);
    }

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
