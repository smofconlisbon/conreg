<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\user\RoleInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Form\Admin\CheckInMembers;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests CheckInMembers's check-in table and submit behavior.
 *
 * Split out of FormBuildTest, which is a "does this form build without
 * error" smoke test - these are behavioral assertions about the table's
 * structure and submit handling, not just "it builds".
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class CheckInMembersKernelTest extends KernelTestBase {

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
   * A stale resubmitted "table" value doesn't warn or select any members.
   *
   * A browser's "confirm form resubmission" refresh prompt can replay a
   * POST body that predates the current form structure, so 'table' isn't
   * guaranteed to come back as an array of member rows.
   */
  public function testCheckInSubmitToleratesMalformedTableValue(): void {
    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->set('eid', 1);
    $formState->setValues(['table' => 'not-an-array']);

    $form = [];
    $formObject->checkInSubmit($form, $formState);

    $this->assertNull($formState->get('action'));
    $this->assertNull($formState->get('toPay'));
  }

  /**
   * A plain check-in clears a printer left from an earlier print attempt.
   *
   * Regression test: checking in-and-printing, then (in the same
   * form-rebuild lifecycle - e.g. after Cancel) doing a plain check-in
   * instead, must not carry the earlier printer choice into
   * buildConfirmForm() - otherwise the confirm step would show a label
   * preview for a check-in that isn't actually printing anything.
   */
  public function testCheckInSubmitClearsPrinterFromEarlierPrintAttempt(): void {
    $mid = $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'is_paid' => 1,
    ]);

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->set('eid', 1);

    $formState->setValues(['table' => [$mid => ['is_checked_in' => 1]], 'printer' => 'bilbo_baggins']);
    $form = ['checkin_actions' => ['printer' => ['#options' => ['bilbo_baggins' => 'Bilbo Baggins']]]];
    $formObject->checkInAndPrintSubmit($form, $formState);
    $this->assertSame('Bilbo Baggins', $formState->get('printerDisplayName'));

    $formState->setValues(['table' => [$mid => ['is_checked_in' => 1]]]);
    $formObject->checkInSubmit($form, $formState);

    $this->assertNull($formState->get('printerMachineName'));
    $this->assertNull($formState->get('printerDisplayName'));
  }

  /**
   * Cancelling out of the confirm step clears a previously chosen printer.
   *
   * Same regression as testCheckInSubmitClearsPrinterFromEarlierPrintAttempt,
   * covering the other path back to the search list.
   */
  public function testCancelActionClearsPrinterFromEarlierPrintAttempt(): void {
    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->set('eid', 1);
    $formState->set('printerMachineName', 'bilbo_baggins');
    $formState->set('printerDisplayName', 'Bilbo Baggins');

    $form = [];
    $formObject->cancelAction($form, $formState);

    $this->assertNull($formState->get('printerMachineName'));
    $this->assertNull($formState->get('printerDisplayName'));
  }

  /**
   * The check-in table's checkbox column is first, not last.
   */
  public function testAdminCheckInTableHasCheckboxColumnFirst(): void {
    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();

    $form = $formObject->buildForm([], $formState, 1, 0);

    $headerKeys = array_keys($form['table']['#header']);
    $this->assertSame('is_checked_in', reset($headerKeys));
    $this->assertNotSame('is_checked_in', end($headerKeys));
  }

  /**
   * Not-checked-in members get a checkbox cell first; checked-in don't.
   *
   * Not-checked-in members get a checkbox cell first, with a row class the
   * row-click-toggle JS hooks into. Checked-in members get plain "Checked
   * in" text instead, with no checkbox and no row-click class.
   */
  public function testAdminCheckInTableRowCheckboxAndRowClass(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'RowOnePending',
      'is_paid' => 1,
      'is_checked_in' => 0,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 2,
      'first_name' => 'RowTwoDone',
      'is_paid' => 1,
      'is_checked_in' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->setValues(['search' => 'Row']);

    $form = $formObject->buildForm([], $formState, 1, 0);

    // Not-checked-in member: checkbox is the first cell, carries the
    // select-all class, and the row carries the click-toggle class.
    $pendingRow = $form['table'][1];
    $pendingKeys = array_keys($pendingRow);
    $this->assertSame('is_checked_in', reset($pendingKeys));
    $this->assertSame('checkbox', $pendingRow['is_checked_in']['#type']);
    $this->assertContains('checkbox-selectable', $pendingRow['is_checked_in']['#attributes']['class']);
    $this->assertContains('conreg-table-row--selectable', $pendingRow['#attributes']['class']);

    // Checked-in member: plain markup, no checkbox, no row-click class.
    $doneRow = $form['table'][2];
    $this->assertArrayNotHasKey('#type', $doneRow['is_checked_in']);
    $this->assertArrayHasKey('#markup', $doneRow['is_checked_in']);
    $this->assertArrayNotHasKey('#attributes', $doneRow);
  }

  /**
   * A "select all" checkbox is rendered below the table.
   *
   * It's wired for conreg_select_all.js.
   */
  public function testAdminCheckInFormHasSelectAllCheckbox(): void {
    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();

    $form = $formObject->buildForm([], $formState, 1, 0);

    $this->assertSame('checkbox', $form['select_all_wrapper']['select_all']['#type']);
    $this->assertContains('select-all', $form['select_all_wrapper']['select_all']['#attributes']['class']);
    $this->assertContains('conreg/conreg_select_all', $form['#attached']['library']);
    // Also wired for conreg_selectable_row.js's click-anywhere-on-the-row
    // toggle - see testAdminCheckInTableRowCheckboxAndRowClass.
    $this->assertContains('conreg/conreg_selectable_row', $form['#attached']['library']);
  }

  /**
   * The check-in table's action column is rendered last, after "Paid".
   */
  public function testAdminCheckInTableHasActionColumnLast(): void {
    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();

    $form = $formObject->buildForm([], $formState, 1, 0);

    $headerKeys = array_keys($form['table']['#header']);
    $this->assertSame('action', end($headerKeys));
  }

  /**
   * A not-checked-in row's action cell is a dropbutton of modal-dialog links.
   *
   * A checked-in row's action cell is empty (reserved for future actions).
   */
  public function testAdminCheckInTableActionCellForPendingAndCheckedInRows(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'ActionRowPending',
      'badge_name' => 'Original Name',
      'is_paid' => 1,
      'is_checked_in' => 0,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 2,
      'first_name' => 'ActionRowDone',
      'is_paid' => 1,
      'is_checked_in' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->setValues(['search' => 'ActionRow']);

    $form = $formObject->buildForm([], $formState, 1, 0);

    $pendingAction = $form['table'][1]['action'];
    $this->assertSame('dropbutton', $pendingAction['#type']);

    $badgeNameLink = $pendingAction['#links']['badge_name'];
    $this->assertSame('Badge name', (string) $badgeNameLink['title']);
    $this->assertContains('use-ajax', $badgeNameLink['attributes']['class']);
    $this->assertSame('modal', $badgeNameLink['attributes']['data-dialog-type']);
    $this->assertSame('conreg_admin_checkin_badge_name', $badgeNameLink['url']->getRouteName());
    $this->assertSame(['eid' => 1, 'mid' => '1'], $badgeNameLink['url']->getRouteParameters());

    $previewLink = $pendingAction['#links']['preview_label'];
    $this->assertSame('Preview label', (string) $previewLink['title']);
    $this->assertContains('use-ajax', $previewLink['attributes']['class']);
    $this->assertSame('modal', $previewLink['attributes']['data-dialog-type']);
    $this->assertSame('conreg_admin_checkin_label_preview', $previewLink['url']->getRouteName());
    $this->assertSame(['eid' => 1, 'mid' => '1'], $previewLink['url']->getRouteParameters());

    $this->assertSame('conreg-badge-name-cell-1', $form['table'][1]['badge_name']['#wrapper_attributes']['id']);

    $this->assertSame([], $form['table'][2]['action']);
  }

  /**
   * A checked-in row's action cell is empty without the undo permission.
   *
   * The default kernel test user (anonymous, no roles) has no
   * permissions, so this needs no extra setup.
   */
  public function testAdminCheckInTableActionCellHidesUndoCheckInWithoutPermission(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'UndoRowNoPermission',
      'is_paid' => 1,
      'is_checked_in' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->setValues(['search' => 'UndoRowNoPermission']);
    $form = $formObject->buildForm([], $formState, 1, 0);

    $this->assertSame([], $form['table'][1]['action']);
  }

  /**
   * A checked-in row's action cell has an "Undo check-in" dropbutton link.
   *
   * Only shown to a user with the "undo convention member check-in"
   * permission. Label printing is enabled here (unlike most other
   * tests) specifically to prove "Reprint label" needs its own separate
   * "reprint convention member badge label" permission - see
   * testAdminCheckInTableActionCellShowsReprintLabelWithoutUndoPermission
   * for the converse.
   */
  public function testAdminCheckInTableActionCellShowsUndoCheckInWithPermission(): void {
    $this->installEntitySchema('conreg_printer');
    Printer::create(['eid' => 1, 'name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins'])->save();
    $this->config('conreg.settings.1')->set('checkin.label_printing_enabled', TRUE)->save();

    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['undo convention member check-in']);
    $this->container->get('current_user')->setAccount(new UserSession([
      'uid' => 2,
      'roles' => [RoleInterface::AUTHENTICATED_ID],
    ]));

    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'UndoRowWithPermission',
      'is_paid' => 1,
      'is_checked_in' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->setValues(['search' => 'UndoRowWithPermission']);
    $form = $formObject->buildForm([], $formState, 1, 0);

    $doneAction = $form['table'][1]['action'];
    $this->assertSame('dropbutton', $doneAction['#type']);

    $undoLink = $doneAction['#links']['undo_check_in'];
    $this->assertSame('Undo check-in', (string) $undoLink['title']);
    $this->assertContains('use-ajax', $undoLink['attributes']['class']);
    $this->assertSame('modal', $undoLink['attributes']['data-dialog-type']);
    $this->assertSame('conreg_admin_checkin_undo', $undoLink['url']->getRouteName());
    $this->assertSame(['eid' => 1, 'mid' => '1'], $undoLink['url']->getRouteParameters());
    // Carries the active search so UndoCheckInForm's post-confirm
    // redirect can restore it - see
    // testAdminCheckInFormPrefillsAndRunsSearchFromQueryParameter.
    $this->assertSame(['search' => 'UndoRowWithPermission'], $undoLink['url']->getOption('query'));

    // Printing is enabled, but this user was never granted "reprint
    // convention member badge label", so "Reprint label" must not show.
    $this->assertArrayNotHasKey('reprint_label', $doneAction['#links']);
  }

  /**
   * A checked-in row's dropbutton gets a "Reprint label" link.
   *
   * Shown to a user with "reprint convention member badge label" - a
   * separate permission from "undo convention member check-in" - and
   * only when label printing is enabled for the event, since reprinting
   * a lost badge makes no sense on an event that never prints labels at
   * all. Both permissions are granted here to also confirm they can
   * coexist on the dropbutton; see
   * testAdminCheckInTableActionCellShowsReprintLabelWithoutUndoPermission
   * for proof the reprint permission works on its own.
   */
  public function testAdminCheckInTableActionCellShowsReprintLabelWhenPrintingEnabled(): void {
    $this->installEntitySchema('conreg_printer');
    Printer::create(['eid' => 1, 'name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins'])->save();
    $this->config('conreg.settings.1')->set('checkin.label_printing_enabled', TRUE)->save();

    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, [
      'undo convention member check-in',
      'reprint convention member badge label',
    ]);
    $this->container->get('current_user')->setAccount(new UserSession([
      'uid' => 2,
      'roles' => [RoleInterface::AUTHENTICATED_ID],
    ]));

    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'ReprintRowWithPermission',
      'is_paid' => 1,
      'is_checked_in' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->setValues(['search' => 'ReprintRowWithPermission']);
    $form = $formObject->buildForm([], $formState, 1, 0);

    $links = $form['table'][1]['action']['#links'];
    $this->assertArrayHasKey('undo_check_in', $links);

    $reprintLink = $links['reprint_label'];
    $this->assertSame('Reprint label', (string) $reprintLink['title']);
    $this->assertContains('use-ajax', $reprintLink['attributes']['class']);
    $this->assertSame('modal', $reprintLink['attributes']['data-dialog-type']);
    $this->assertSame('conreg_admin_checkin_reprint_label', $reprintLink['url']->getRouteName());
    $this->assertSame(['eid' => 1, 'mid' => '1'], $reprintLink['url']->getRouteParameters());
  }

  /**
   * Reprint label works with only its own permission, without Undo.
   *
   * Regression test for splitting the two actions onto separate
   * permissions: a user granted only "reprint convention member badge
   * label" must see "Reprint label" but not "Undo check-in".
   */
  public function testAdminCheckInTableActionCellShowsReprintLabelWithoutUndoPermission(): void {
    $this->installEntitySchema('conreg_printer');
    Printer::create(['eid' => 1, 'name' => 'Bilbo Baggins', 'machine_name' => 'bilbo_baggins'])->save();
    $this->config('conreg.settings.1')->set('checkin.label_printing_enabled', TRUE)->save();

    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['reprint convention member badge label']);
    $this->container->get('current_user')->setAccount(new UserSession([
      'uid' => 2,
      'roles' => [RoleInterface::AUTHENTICATED_ID],
    ]));

    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'ReprintOnlyRow',
      'is_paid' => 1,
      'is_checked_in' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $formState->setValues(['search' => 'ReprintOnlyRow']);
    $form = $formObject->buildForm([], $formState, 1, 0);

    $links = $form['table'][1]['action']['#links'];
    $this->assertArrayHasKey('reprint_label', $links);
    $this->assertArrayNotHasKey('undo_check_in', $links);
  }

  /**
   * A fresh page load pre-fills and runs a search from ?search=.
   *
   * UndoCheckInForm redirects back here with the search that was active
   * before "Undo check-in" was clicked (see conreg_admin_checkin_undo's
   * getRedirectUrl()) - confirming shouldn't land back on an empty search
   * box with the results reset.
   */
  public function testAdminCheckInFormPrefillsAndRunsSearchFromQueryParameter(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'QueryParamSearch',
      'is_paid' => 1,
      'is_checked_in' => 0,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
    ]);

    $request = Request::create('/admin/members/checkin/1/0', 'GET', ['search' => 'QueryParamSearch']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $formObject = CheckInMembers::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, 0);

    $this->assertSame('QueryParamSearch', $form['search_wrapper']['search']['#default_value']);
    $this->assertArrayHasKey(1, $form['table']);
  }

}
