<?php

namespace Drupal\conreg\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the badge label print job exchange API.
 */
class PrintJobController extends ControllerBase {

  /**
   * Returns the next print job for an agent to process.
   *
   * TEMPORARY: hardcoded single job for manual round-trip testing.
   * Replaced by a real queue/claim mechanism against a print_jobs
   * entity in a follow-up issue, at which point $eid will scope the
   * query instead of just matching the route.
   */
  public function next(int $eid): JsonResponse {
    $job = [
      'job_id' => '1',
      'member_name' => 'Jane Doe',
      'member_number' => 'M-4021',
      'days_attending' => 'Fri-Sun',
    ];
    // Set $job = NULL above to manually exercise the "no job" (204) path.
    if ($job === NULL) {
      return new JsonResponse(NULL, 204);
    }
    return new JsonResponse($job);
  }

  /**
   * Accepts a print result from an agent and logs it.
   *
   * TEMPORARY: no persistence, just \Drupal::logger(). Replaced by
   * status updates against a real print_jobs entity in a follow-up.
   */
  public function result(Request $request, int $eid, string $id): JsonResponse {
    $data = json_decode($request->getContent(), TRUE) ?? [];
    $status = $data['status'] ?? 'unknown';
    $message = $data['message'] ?? '';

    \Drupal::logger('conreg')->notice(
      'Print job result: eid=@eid id=@id status=@status message=@message',
      ['@eid' => $eid, '@id' => $id, '@status' => $status, '@message' => $message]
    );

    return new JsonResponse(['received' => TRUE]);
  }

}
