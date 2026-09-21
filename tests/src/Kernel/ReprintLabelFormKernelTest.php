<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Form\Admin\ReprintLabelForm;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests ReprintLabelForm, the "Reprint label" confirmation modal.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ReprintLabelFormKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
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
   * Set up database tables and config for testing forms.
   */
  protected function setUp(): void {
    parent::setUp();

    // Required for #type = datetime.
    $this->installConfig(['system', 'datetime']);

    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
      'conreg_payments',
      'conreg_payment_lines',
    ]);
    $this->installEntitySchema('conreg_printer');
    $this->installEntitySchema('conreg_print_job');
    $this->installConfig(['conreg']);

    // Required for MemberTypes.php's default text format lookup - see
    // UndoCheckInFormKernelTest for the full explanation.
    $this->installConfig(['filter', 'user']);
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'weight' => 0,
      'filters' => [],
    ])->save();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['use text format basic_html']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();

    // Every test in this file exercises the reprint flow itself, so
    // label printing is on by default here - see
    // testBuildFormShowsMessageWhenLabelPrintingDisabled for the one
    // test of the gate itself.
    $this->config('conreg.settings.1')->set('checkin.label_printing_enabled', TRUE)->save();
  }

  /**
   * Create a member with most required fields.
   */
  protected function createTestMember(array $overrides = []): int {
    $defaults = [
      'mid' => 1,
      'eid' => 1,
      'language' => 'en',
      'first_name' => 'Test',
      'last_name' => 'User',
      'email' => 'test@example.com',
      'join_date' => \Drupal::time()->getCurrentTime(),
      'update_date' => \Drupal::time()->getCurrentTime(),
    ];

    $fields = $overrides + $defaults;

    return Database::getConnection()
      ->insert('conreg_members')
      ->fields($fields)
      ->execute();
  }

  /**
   * Create a printer entity and push a request with a session onto the stack.
   *
   * Needed for any test exercising PrinterSessionTrait (getRememberedPrinter/
   * rememberPrinter), since those read/write $this->getRequest()->getSession().
   */
  protected function createPrinter(int $eid, string $name, ?string $machineName = NULL): Printer {
    $machineName ??= strtolower(str_replace(' ', '_', $name));
    $printer = Printer::create(['eid' => $eid, 'name' => $name, 'machine_name' => $machineName]);
    $printer->save();
    return $printer;
  }

  /**
   * Pushes a request with a real (mock-backed) session onto the stack.
   */
  protected function pushRequestWithSession(array $query = []): void {
    $request = Request::create('/admin/members/checkin/1/1/reprint-label', 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Shows a preview image and a printer select when a printer is configured.
   */
  public function testBuildFormShowsPreviewAndPrinterSelect(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $this->assertStringStartsWith('data:image/png;base64,', $form['preview']['#attributes']['src']);
    $this->assertSame(['bilbo_baggins' => 'Bilbo Baggins'], $form['printer']['#options']);
    $this->assertNull($form['printer']['#default_value']);
    $this->assertStringContainsString('Jane Doe', (string) $form['question']['#markup']);
    $this->assertSame('Print', (string) $form['actions']['confirm']['#value']);
    $this->assertSame('Cancel', (string) $form['actions']['cancel']['#value']);
  }

  /**
   * Defaults the printer select to the session's remembered printer.
   */
  public function testBuildFormDefaultsToRememberedPrinter(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');
    $this->createPrinter(1, 'Frodo Baggins', 'frodo_baggins');

    $this->container->get('request_stack')->getCurrentRequest()
      ->getSession()->set('conreg_checkin_printer_1', 'frodo_baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $this->assertSame('frodo_baggins', $form['printer']['#default_value']);
  }

  /**
   * Shows a message instead of building the form when printing is off.
   *
   * Regression test: the "Reprint label" dropbutton link is already
   * hidden by CheckInMembers.php when this is off, but that's only a
   * UI nicety - this route is reachable directly, so the actual gate
   * has to live here too.
   */
  public function testBuildFormShowsMessageWhenLabelPrintingDisabled(): void {
    $this->config('conreg.settings.1')->set('checkin.label_printing_enabled', FALSE)->save();
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $this->assertArrayHasKey('message', $form);
    $this->assertArrayNotHasKey('preview', $form);
    $this->assertArrayNotHasKey('printer', $form);
  }

  /**
   * Shows a warning instead of a printer select when none are configured.
   */
  public function testBuildFormShowsMessageWhenNoPrintersConfigured(): void {
    $this->pushRequestWithSession();

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $this->assertArrayNotHasKey('printer', $form);
    $this->assertArrayHasKey('no_printers', $form);
    $this->assertSame('Cancel', (string) $form['actions']['cancel']['#value']);
    $this->assertArrayNotHasKey('confirm', $form['actions']);
  }

  /**
   * Confirming queues a print job and remembers the chosen printer.
   */
  public function testSubmitFormQueuesJobAndRemembersPrinter(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $formState->setValue('printer', 'bilbo_baggins');
    $formObject->submitForm($form, $formState);

    $jobStorage = $this->container->get('entity_type.manager')->getStorage('conreg_print_job');
    $jobs = $jobStorage->loadByProperties(['mid' => $mid]);
    $job = reset($jobs);
    $this->assertNotFalse($job);
    $this->assertSame('Jane Doe', $job->get('member_name')->value);
    $this->assertSame('pending', $job->get('status')->value);

    $this->assertSame(
      'bilbo_baggins',
      $this->container->get('request_stack')->getCurrentRequest()->getSession()->get('conreg_checkin_printer_1'),
    );
  }

  /**
   * Confirming with a valid printer redirects back to Member Check-In.
   */
  public function testAjaxConfirmRedirectsWhenValid(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $formState->setValue('printer', 'bilbo_baggins');
    $formObject->submitForm($form, $formState);

    $ajaxResponse = $formObject->ajaxConfirm($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('redirect', $commands[0]['command']);
    $this->assertStringContainsString('/admin/members/checkin', $commands[0]['url']);
  }

  /**
   * The post-confirm redirect carries the ?search= that opened the modal.
   *
   * Regression test: CheckInMembers.php's reprint_label link now carries
   * the active search the same way its undo_check_in link always has -
   * confirming shouldn't land back on an empty search box.
   */
  public function testAjaxConfirmRedirectsWithSearchFromQueryParameter(): void {
    $this->pushRequestWithSession(['search' => 'Picard']);
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $formState->setValue('printer', 'bilbo_baggins');
    $formObject->submitForm($form, $formState);

    $ajaxResponse = $formObject->ajaxConfirm($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertStringContainsString('search=Picard', $commands[0]['url']);
  }

  /**
   * A badge name of literally "0" is shown as-is, not the member's full name.
   *
   * Regression test: `$member['badge_name'] ?: ...` (the Elvis operator)
   * treats the string "0" as falsy, wrongly falling back to the full
   * name - see MemberDisplayNameTrait.
   */
  public function testBuildFormShowsBadgeNameOfZero(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => '0',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $question = (string) $form['question']['#markup'];
    $this->assertStringNotContainsString('Jane Doe', $question);
    $this->assertMatchesRegularExpression('/\b0\b/', $question);
  }

  /**
   * Confirming reuses the exact preview image, without re-rendering it.
   *
   * Regression test: submitForm() used to call PrintJobManager::
   * createJob(), which re-renders the label from scratch even though
   * buildForm() just rendered the identical preview moments earlier.
   * It now reuses those same bytes via createJobWithRenderedImage() -
   * confirmed here by checking the queued job's image is byte-identical
   * to what the preview showed, not just similar.
   */
  public function testSubmitFormReusesPreviewImageWithoutRerendering(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $previewSrc = $form['preview']['#attributes']['src'];
    $this->assertStringStartsWith('data:image/png;base64,', $previewSrc);
    $expectedImage = substr($previewSrc, strlen('data:image/png;base64,'));

    $formState->setValue('printer', 'bilbo_baggins');
    $formObject->submitForm($form, $formState);

    $jobStorage = $this->container->get('entity_type.manager')->getStorage('conreg_print_job');
    $jobs = $jobStorage->loadByProperties(['mid' => $mid]);
    $job = reset($jobs);
    $this->assertSame($expectedImage, $job->get('image_data')->value);
  }

  /**
   * A validation error re-renders the modal instead of redirecting away.
   *
   * Confirming with no printer selected fails the "printer" field's
   * #required validation - ConfirmModalFormBase::ajaxConfirm() detects
   * this and re-renders the modal rather than redirecting past it
   * (see its docblock).
   */
  public function testAjaxConfirmReRendersFormOnValidationError(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $formState->setErrorByName('printer', 'Select a printer.');

    $ajaxResponse = $formObject->ajaxConfirm($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertSame('insert', $commands[0]['command']);
    $this->assertSame('.conreg-confirm-modal-form', $commands[0]['selector']);

    // No job should have been queued.
    $jobStorage = $this->container->get('entity_type.manager')->getStorage('conreg_print_job');
    $this->assertSame([], $jobStorage->loadByProperties(['mid' => $mid]));
  }

  /**
   * Clicking Cancel, via Drupal's real submit-handler resolution, is a no-op.
   *
   * Regression test for a real bug: the cancel button's '#submit' must
   * resolve to a non-empty handler list. If it were `[]`, FormSubmitter::
   * executeSubmitHandlers() treats that as "no override" and falls back
   * to the form's top-level #submit - which always includes the form
   * object's own submitForm() - so Cancel would silently queue a print
   * job anyway. ajaxCancel() alone (see testAjaxCancelDoesNotQueueJob)
   * doesn't exercise this, since it never goes through FormSubmitter -
   * this test calls the real 'form_submitter' service the way
   * FormBuilder does when a button is actually clicked.
   */
  public function testCancelSubmitHandlerDoesNotQueueJob(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    // Needed to resolve the '::cancelSubmit' callback below - normally
    // set by the form_builder service, which this test bypasses by
    // calling buildForm() directly.
    $formState->setFormObject($formObject);
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $formState->setTriggeringElement($form['actions']['cancel']);
    $formState->setSubmitHandlers($form['actions']['cancel']['#submit']);
    $formState->setSubmitted();
    $this->container->get('form_submitter')->executeSubmitHandlers($form, $formState);

    $jobStorage = $this->container->get('entity_type.manager')->getStorage('conreg_print_job');
    $this->assertSame([], $jobStorage->loadByProperties(['mid' => $mid]));
  }

  /**
   * Declining closes the dialog without queueing a job.
   */
  public function testAjaxCancelDoesNotQueueJob(): void {
    $this->pushRequestWithSession();
    $this->createPrinter(1, 'Bilbo Baggins');

    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $ajaxResponse = $formObject->ajaxCancel($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('closeDialog', $commands[0]['command']);

    $jobStorage = $this->container->get('entity_type.manager')->getStorage('conreg_print_job');
    $this->assertSame([], $jobStorage->loadByProperties(['mid' => $mid]));
  }

  /**
   * Building for a member of a different event is rejected.
   *
   * Regression test: this route's {eid} was previously used to look up
   * the printer list without ever checking it against the member's own
   * event - see AssertMemberEventTrait.
   */
  public function testBuildFormThrowsForMemberOfDifferentEvent(): void {
    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Other event', 'is_open' => 1])
      ->execute();
    $mid = $this->createTestMember([
      'mid' => 1,
      'eid' => 2,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = ReprintLabelForm::create($this->container);
    $formState = new FormState();

    $this->expectException(NotFoundHttpException::class);
    $formObject->buildForm([], $formState, 1, $mid);
  }

}
