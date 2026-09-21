<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\user\RoleInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\conreg\Form\Admin\UndoCheckInForm;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests UndoCheckInForm, the "Undo check-in" confirmation modal.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class UndoCheckInFormKernelTest extends KernelTestBase {

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
    $this->installConfig(['conreg']);

    // Required for MemberTypes.php's default text format lookup, now that
    // 'filter' is enabled (a dependency of easy_email/text). A real site
    // has a 'basic_html' format (normally provided by the 'standard'
    // install profile, which this minimal Kernel test doesn't use). Must
    // come after the conreg schema/config above - saving user role config
    // rebuilds the permission list, which includes conreg's per-event
    // dynamic permissions (FieldOptionPermissions) and so queries
    // conreg_events.
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
   * UndoCheckInForm shows a question naming the member's badge name.
   */
  public function testBuildFormShowsQuestionWithMemberName(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = UndoCheckInForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $this->assertStringContainsString('Jane Doe', (string) $form['question']['#markup']);
    $this->assertSame('submit', $form['actions']['confirm']['#type']);
    $this->assertSame('Yes', (string) $form['actions']['confirm']['#value']);
    $this->assertSame('::ajaxConfirm', $form['actions']['confirm']['#ajax']['callback']);
    $this->assertSame('submit', $form['actions']['cancel']['#type']);
    $this->assertSame('No', (string) $form['actions']['cancel']['#value']);
    // Must resolve to a real no-op handler, not [] - see
    // testCancelSubmitHandlerDoesNotUndoCheckIn for why an empty array
    // is actually a live bug (Cancel silently running the confirm
    // action anyway), not just an inert default.
    $this->assertSame(['::cancelSubmit'], $form['actions']['cancel']['#submit']);
    $this->assertSame('::ajaxCancel', $form['actions']['cancel']['#ajax']['callback']);
  }

  /**
   * Falls back to the member's full name when there's no badge name.
   */
  public function testBuildFormFallsBackToFullNameWhenNoBadgeName(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'John',
      'last_name' => 'Smith',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = UndoCheckInForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $this->assertStringContainsString('John Smith', (string) $form['question']['#markup']);
  }

  /**
   * A badge name of literally "0" is shown as-is, not the member's full name.
   *
   * Regression test: `$member['badge_name'] ?: ...` (the Elvis operator)
   * treats the string "0" as falsy, wrongly falling back to the full
   * name - see MemberDisplayNameTrait.
   */
  public function testBuildFormShowsBadgeNameOfZero(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => '0',
      'first_name' => 'John',
      'last_name' => 'Smith',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = UndoCheckInForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $question = (string) $form['question']['#markup'];
    $this->assertStringNotContainsString('John Smith', $question);
    $this->assertMatchesRegularExpression('/\b0\b/', $question);
  }

  /**
   * Confirming sets the member back to not checked in and redirects.
   */
  public function testAjaxConfirmUndoesCheckInAndRedirects(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = UndoCheckInForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $formObject->submitForm($form, $formState);

    $saved = Database::getConnection()
      ->select('conreg_members', 'm')
      ->fields('m', ['is_checked_in'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchField();
    $this->assertSame('0', $saved);

    $ajaxResponse = $formObject->ajaxConfirm($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('redirect', $commands[0]['command']);
    // eid=1 is the route's own default, so Symfony's URL generator omits
    // it (along with the also-default lead_mid) from the generated path -
    // this only checks it points at the check-in route, not its exact
    // path string.
    $this->assertStringContainsString('/admin/members/checkin', $commands[0]['url']);
  }

  /**
   * Confirming redirects back with the ?search= that opened the modal.
   *
   * CheckInMembers.php puts the active search on the "Undo check-in" link
   * as a ?search= query parameter (see CheckInMembersKernelTest's
   * testAdminCheckInTableActionCellShowsUndoCheckInWithPermission) - this
   * confirms UndoCheckInForm reads it back and carries it through to the
   * redirect, so confirming doesn't land back on an empty search box.
   */
  public function testAjaxConfirmRedirectsWithSearchFromQueryParameter(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $request = Request::create('/admin/members/checkin/1/0/undo-checkin', 'GET', ['search' => 'Picard']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $formObject = UndoCheckInForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);
    $formObject->submitForm($form, $formState);

    $ajaxResponse = $formObject->ajaxConfirm($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertSame('redirect', $commands[0]['command']);
    $this->assertStringContainsString('search=Picard', $commands[0]['url']);
  }

  /**
   * Clicking No, via Drupal's real submit-handler resolution, is a no-op.
   *
   * Regression test for a real bug: the cancel button's '#submit' must
   * resolve to a non-empty handler list. If it were `[]`, FormSubmitter::
   * executeSubmitHandlers() treats that as "no override" and falls back
   * to the form's top-level #submit - which always includes the form
   * object's own submitForm() - so clicking "No" would silently undo the
   * check-in anyway. ajaxCancel() alone (see testAjaxCancelDoesNotUndoCheckIn)
   * doesn't exercise this, since it never goes through FormSubmitter -
   * this test calls the real 'form_submitter' service the way
   * FormBuilder does when a button is actually clicked.
   */
  public function testCancelSubmitHandlerDoesNotUndoCheckIn(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = UndoCheckInForm::create($this->container);
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

    $saved = Database::getConnection()
      ->select('conreg_members', 'm')
      ->fields('m', ['is_checked_in'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchField();
    $this->assertSame('1', $saved);
  }

  /**
   * Declining closes the dialog without changing the member's check-in.
   */
  public function testAjaxCancelDoesNotUndoCheckIn(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Jane Doe',
      'is_paid' => 1,
      'is_checked_in' => 1,
    ]);

    $formObject = UndoCheckInForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $ajaxResponse = $formObject->ajaxCancel($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('closeDialog', $commands[0]['command']);

    $saved = Database::getConnection()
      ->select('conreg_members', 'm')
      ->fields('m', ['is_checked_in'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchField();
    $this->assertSame('1', $saved);
  }

  /**
   * Building for a member of a different event is rejected.
   *
   * Regression test: this route's {eid} was previously never checked
   * against the member's own event - see AssertMemberEventTrait.
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

    $formObject = UndoCheckInForm::create($this->container);
    $formState = new FormState();

    $this->expectException(NotFoundHttpException::class);
    $formObject->buildForm([], $formState, 1, $mid);
  }

}
