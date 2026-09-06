<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Controller\PrintJobController;
use Drupal\conreg\Entity\PrintJob;
use Drupal\conreg\Entity\Printer;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests PrintJobController, the badge label print job exchange API.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class PrintJobControllerKernelTest extends KernelTestBase {

  protected const CURRENT_TIME = 1700000000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'options',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('conreg_printer');
    $this->installEntitySchema('conreg_print_job');

    // Fixed timestamp for deterministic tests.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::CURRENT_TIME);
    $this->container->set('datetime.time', $time);
  }

  /**
   * Helper: create a printer entity.
   *
   * Derives a CUPS-safe machine name from the display name (e.g. "Bilbo
   * Baggins" -> "bilbo_baggins") unless one is given explicitly.
   */
  protected function createPrinter(int $eid, string $name, ?string $machineName = NULL): Printer {
    $machineName ??= strtolower(str_replace(' ', '_', $name));
    $printer = Printer::create(['eid' => $eid, 'name' => $name, 'machine_name' => $machineName]);
    $printer->save();
    return $printer;
  }

  /**
   * Helper: create a pending print job entity.
   */
  protected function createJob(int $eid, int $printerId, array $overrides = []): PrintJob {
    $job = PrintJob::create($overrides + [
      'eid' => $eid,
      'mid' => 1,
      'member_name' => 'Jane Doe',
      'member_number' => 'M-4021',
      'days_attending' => 'Fri-Sun',
      'printer' => $printerId,
      'status' => 'pending',
    ]);
    $job->save();
    return $job;
  }

  /**
   * Call the controller the same way the route would.
   */
  protected function callController(): PrintJobController {
    return PrintJobController::create($this->container);
  }

  /**
   * Test that the oldest pending job for the printer is claimed and returned.
   */
  public function testNextReturnsOldestPendingJobForPrinter(): void {
    $printer = $this->createPrinter(1, 'Bilbo Baggins');

    $older = $this->createJob(1, (int) $printer->id(), ['member_name' => 'Older Job']);
    $this->createJob(1, (int) $printer->id(), ['member_name' => 'Newer Job']);

    $request = Request::create('/api/print-jobs/1/next', 'GET', ['printer' => 'bilbo_baggins']);
    $response = $this->callController()->next($request, 1);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame((string) $older->id(), $data['job_id']);
    $this->assertSame('Older Job', $data['member_name']);
    $this->assertSame('M-4021', $data['member_number']);
    $this->assertSame('Fri-Sun', $data['days_attending']);

    $this->container->get('entity_type.manager')->getStorage('conreg_print_job')->resetCache();
    $reloaded = PrintJob::load($older->id());
    $this->assertSame('claimed', $reloaded->get('status')->value);
  }

  /**
   * Test that a second poll doesn't re-claim an already-claimed job.
   */
  public function testNextDoesNotClaimJobTwice(): void {
    $printer = $this->createPrinter(1, 'Bilbo Baggins');
    $this->createJob(1, (int) $printer->id());

    $request = Request::create('/api/print-jobs/1/next', 'GET', ['printer' => 'bilbo_baggins']);
    $controller = $this->callController();

    $first = $controller->next($request, 1);
    $this->assertSame(200, $first->getStatusCode());

    $second = $controller->next($request, 1);
    $this->assertSame(204, $second->getStatusCode());
  }

  /**
   * Test that a job claimed long enough ago with no result is reclaimed.
   *
   * A crashed or disconnected agent could otherwise leave a job "claimed"
   * forever with nothing else happening to it.
   */
  public function testNextReclaimsStaleClaimedJob(): void {
    $printer = $this->createPrinter(1, 'Bilbo Baggins');
    // PrintJobController::STALE_CLAIM_TIMEOUT_SECONDS is 300; one second
    // past that counts as abandoned.
    $job = $this->createJob(1, (int) $printer->id(), [
      'status' => 'claimed',
      'changed' => self::CURRENT_TIME - 301,
    ]);

    $request = Request::create('/api/print-jobs/1/next', 'GET', ['printer' => 'bilbo_baggins']);
    $response = $this->callController()->next($request, 1);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame((string) $job->id(), $data['job_id']);
  }

  /**
   * Test that a job claimed recently is not treated as abandoned.
   */
  public function testNextDoesNotReclaimRecentlyClaimedJob(): void {
    $printer = $this->createPrinter(1, 'Bilbo Baggins');
    $this->createJob(1, (int) $printer->id(), [
      'status' => 'claimed',
      'changed' => self::CURRENT_TIME - 60,
    ]);

    $request = Request::create('/api/print-jobs/1/next', 'GET', ['printer' => 'bilbo_baggins']);
    $response = $this->callController()->next($request, 1);

    $this->assertSame(204, $response->getStatusCode());
  }

  /**
   * Test that a pending job for a different printer is not claimed.
   */
  public function testNextIgnoresJobForDifferentPrinter(): void {
    $printerA = $this->createPrinter(1, 'Bilbo Baggins');
    $this->createPrinter(1, 'Darth Printer');
    $this->createJob(1, (int) $printerA->id());

    $request = Request::create('/api/print-jobs/1/next', 'GET', ['printer' => 'darth_printer']);
    $response = $this->callController()->next($request, 1);

    $this->assertSame(204, $response->getStatusCode());
  }

  /**
   * Test that a pending job under a different event is not claimed.
   */
  public function testNextIgnoresJobForDifferentEvent(): void {
    $printer = $this->createPrinter(1, 'Bilbo Baggins');
    $this->createJob(1, (int) $printer->id());

    // Same printer name, but the request is scoped to a different event.
    $request = Request::create('/api/print-jobs/2/next', 'GET', ['printer' => 'bilbo_baggins']);
    $response = $this->callController()->next($request, 2);

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Test the missing "printer" query parameter is rejected.
   */
  public function testNextRequiresPrinterParameter(): void {
    $request = Request::create('/api/print-jobs/1/next', 'GET');
    $response = $this->callController()->next($request, 1);

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Test an unknown printer name is rejected.
   */
  public function testNextRejectsUnknownPrinter(): void {
    $request = Request::create('/api/print-jobs/1/next', 'GET', ['printer' => 'No Such Printer']);
    $response = $this->callController()->next($request, 1);

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Test 204 is returned when no jobs are pending for the printer.
   */
  public function testNextReturnsNoContentWhenNoJobsPending(): void {
    $this->createPrinter(1, 'Bilbo Baggins');

    $request = Request::create('/api/print-jobs/1/next', 'GET', ['printer' => 'bilbo_baggins']);
    $response = $this->callController()->next($request, 1);

    $this->assertSame(204, $response->getStatusCode());
  }

  /**
   * Test posting a result updates the job's status and message.
   */
  public function testResultUpdatesJobStatus(): void {
    $printer = $this->createPrinter(1, 'Bilbo Baggins');
    $job = $this->createJob(1, (int) $printer->id());

    $request = Request::create(
      '/api/print-jobs/1/' . $job->id() . '/result',
      'POST',
      [],
      [],
      [],
      [],
      json_encode(['status' => 'success', 'message' => 'Printed OK']),
    );

    $response = $this->callController()->result($request, 1, (int) $job->id());

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['received' => TRUE], json_decode($response->getContent(), TRUE));

    $this->container->get('entity_type.manager')->getStorage('conreg_print_job')->resetCache();
    $reloaded = PrintJob::load($job->id());
    $this->assertSame('success', $reloaded->get('status')->value);
    $this->assertSame('Printed OK', $reloaded->get('message')->value);
  }

  /**
   * Test posting a result for a job under the wrong event ID is rejected.
   */
  public function testResultRejectsMismatchedEvent(): void {
    $printer = $this->createPrinter(1, 'Bilbo Baggins');
    $job = $this->createJob(1, (int) $printer->id());

    $request = Request::create(
      '/api/print-jobs/2/' . $job->id() . '/result',
      'POST',
      [],
      [],
      [],
      [],
      json_encode(['status' => 'success']),
    );

    $response = $this->callController()->result($request, 2, (int) $job->id());

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Test posting a result for a job ID that doesn't exist is rejected.
   */
  public function testResultRejectsUnknownJob(): void {
    $request = Request::create(
      '/api/print-jobs/1/999/result',
      'POST',
      [],
      [],
      [],
      [],
      json_encode(['status' => 'success']),
    );

    $response = $this->callController()->result($request, 1, 999);

    $this->assertSame(404, $response->getStatusCode());
  }

}
