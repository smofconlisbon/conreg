<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\user\RoleInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\conreg\Form\Admin\CheckInBadgeNameForm;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests CheckInBadgeNameForm's AJAX submit/cancel behavior.
 *
 * Split out of FormBuildTest, which is a "does this form build without
 * error" smoke test (see testCheckInBadgeNameFormBuild there) - these are
 * behavioral assertions about persistence and the AJAX commands
 * returned, not just "it builds".
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class CheckInBadgeNameFormKernelTest extends KernelTestBase {

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
   * CheckInBadgeNameForm's AJAX submit saves and returns the right commands.
   */
  public function testCheckInBadgeNameFormAjaxSubmitPersistsAndReturnsAjaxCommands(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Old Name',
      'is_paid' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInBadgeNameForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $formState->setValues(['badge_name' => '  New Name  ']);
    $formObject->submitForm($form, $formState);

    $saved = Database::getConnection()
      ->select('conreg_members', 'm')
      ->fields('m', ['badge_name'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchField();
    $this->assertSame('New Name', $saved);

    $ajaxResponse = $formObject->ajaxSubmit($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertCount(2, $commands);
    $this->assertSame('closeDialog', $commands[0]['command']);
    $this->assertSame('insert', $commands[1]['command']);
    $this->assertSame('html', $commands[1]['method']);
    $this->assertSame('#conreg-badge-name-cell-' . $mid, $commands[1]['selector']);
    $this->assertStringContainsString('New Name', $commands[1]['data']);
  }

  /**
   * Clicking Cancel, via Drupal's real submit-handler resolution, is a no-op.
   *
   * Regression test for a real bug: the cancel button's '#submit' must
   * resolve to a non-empty handler list. If it were `[]`, FormSubmitter::
   * executeSubmitHandlers() treats that as "no override" and falls back
   * to the form's top-level #submit - which always includes the form
   * object's own submitForm() - so clicking Cancel would silently save
   * the badge name anyway. ajaxCancel() alone (see
   * testCheckInBadgeNameFormAjaxCancelDoesNotSave) doesn't exercise this,
   * since it never goes through FormSubmitter - this test calls the real
   * 'form_submitter' service the way FormBuilder does when a button is
   * actually clicked.
   */
  public function testCancelSubmitHandlerDoesNotSave(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Old Name',
      'is_paid' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInBadgeNameForm::create($this->container);
    $formState = new FormState();
    // Needed to resolve the '::cancelSubmit' callback below - normally
    // set by the form_builder service, which this test bypasses by
    // calling buildForm() directly.
    $formState->setFormObject($formObject);
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $formState->setValues(['badge_name' => 'Attempted Change']);
    $formState->setTriggeringElement($form['actions']['cancel']);
    $formState->setSubmitHandlers($form['actions']['cancel']['#submit']);
    $formState->setSubmitted();
    $this->container->get('form_submitter')->executeSubmitHandlers($form, $formState);

    $saved = Database::getConnection()
      ->select('conreg_members', 'm')
      ->fields('m', ['badge_name'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchField();
    $this->assertSame('Old Name', $saved);
  }

  /**
   * CheckInBadgeNameForm's Cancel button closes the dialog without saving.
   */
  public function testCheckInBadgeNameFormAjaxCancelDoesNotSave(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'badge_name' => 'Old Name',
      'is_paid' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInBadgeNameForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);

    $ajaxResponse = $formObject->ajaxCancel($form, $formState);
    $commands = $ajaxResponse->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('closeDialog', $commands[0]['command']);

    $saved = Database::getConnection()
      ->select('conreg_members', 'm')
      ->fields('m', ['badge_name'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchField();
    $this->assertSame('Old Name', $saved);
  }

  /**
   * Building for a member of a different event is rejected.
   *
   * Regression test: this route's {eid} was previously only used for
   * member-type/class lookups, never checked against the member's own
   * event - see AssertMemberEventTrait.
   */
  public function testBuildThrowsForMemberOfDifferentEvent(): void {
    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Other event', 'is_open' => 1])
      ->execute();
    $mid = $this->createTestMember([
      'mid' => 1,
      'eid' => 2,
      'lead_mid' => 1,
      'is_paid' => 1,
    ]);

    $formObject = CheckInBadgeNameForm::create($this->container);
    $formState = new FormState();

    $this->expectException(NotFoundHttpException::class);
    $formObject->buildForm([], $formState, 1, $mid);
  }

}
