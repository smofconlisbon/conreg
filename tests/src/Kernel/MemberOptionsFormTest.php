<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Admin\MemberOptions;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the Member Options admin page's per-option permissions.
 *
 * Each option is only listed, and only shown as a column, for users with
 * that option's "view field option" permission.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MemberOptionsFormTest extends KernelTestBase {

  use UserCreationTrait;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'datetime']);
    $this->installEntitySchema('user');
    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_options',
    ]);
    $this->installConfig(['conreg']);

    $connection = Database::getConnection();
    $connection->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();

    // One group with two options. Must be in place before any permissions
    // are granted, since FieldOptionPermissions derives them from it.
    $this->config('conreg.settings.1')
      ->set('conreg_options.option_groups', ['1|checkboxes|volunteer|Volunteering|0|1'])
      ->set('conreg_options.options', [
        '11|1|Set up||0|0|Default|0|0|',
        '12|1|Take down||0|1|Default|0|0|',
      ])
      ->save();

    // A paid member who selected both options.
    $connection->insert('conreg_members')
      ->fields([
        'mid' => 1,
        'eid' => 1,
        'language' => 'en',
        'join_date' => \Drupal::time()->getCurrentTime(),
        'update_date' => \Drupal::time()->getCurrentTime(),
        'first_name' => 'Pat',
        'last_name' => 'Volunteer',
        'email' => 'pat@example.com',
        'is_paid' => 1,
        'is_deleted' => 0,
      ])
      ->execute();
    foreach ([11, 12] as $optid) {
      $connection->insert('conreg_member_options')
        ->fields([
          'mid' => 1,
          'optid' => $optid,
          'is_selected' => 1,
          'option_detail' => '',
        ])
        ->execute();
    }
  }

  /**
   * Only options the user has permission for are listed.
   */
  public function testOnlyPermittedOptionsAreListed(): void {
    $this->setUpCurrentUser([], ['view membership options', 'view field option 11 event 1']);

    $form = $this->buildForm();

    $this->assertSame(['1', '1_11'], array_map('strval', array_keys($form['selOption']['#options'])));
  }

  /**
   * A user without any option permissions gets the "no permission" message.
   */
  public function testNoOptionPermissionsShowsMessage(): void {
    $this->setUpCurrentUser([], ['view membership options']);

    $form = $this->buildForm();

    $this->assertArrayNotHasKey('selOption', $form);
    $this->assertStringContainsString("You don't have permission to see any options", (string) $form['conreg_event']['#markup']);
  }

  /**
   * Selecting a group only shows columns for permitted options.
   */
  public function testGroupSelectionShowsOnlyPermittedColumns(): void {
    $this->setUpCurrentUser([], ['view membership options', 'view field option 11 event 1']);

    $form = $this->buildForm('1');

    $this->assertArrayHasKey('option_11', $form['table']['#header']);
    $this->assertArrayNotHasKey('option_12', $form['table']['#header']);
    $this->assertCount(1, $form['table']['#rows']);
    $this->assertSame('✓ ', $form['table']['#rows'][0]['option_11']['data']);
  }

  /**
   * Selecting a single option shows that option's column.
   */
  public function testSingleOptionSelectionShowsColumn(): void {
    $this->setUpCurrentUser([], [
      'view membership options',
      'view field option 11 event 1',
      'view field option 12 event 1',
    ]);

    $form = $this->buildForm('1_12');

    $this->assertSame('1_12', $form['selOption']['#default_value']);
    $this->assertArrayHasKey('option_12', $form['table']['#header']);
    $this->assertArrayNotHasKey('option_11', $form['table']['#header']);
  }

  /**
   * The page lists the event's options and members through its route.
   */
  public function testPageShowsOptions(): void {
    $this->setUpCurrentUser([], [
      'view membership options',
      'view field option 11 event 1',
      'view field option 12 event 1',
    ]);

    $response = $this->requestPage('1');

    $this->assertSame(200, $response->getStatusCode());
    $content = (string) $response->getContent();
    $this->assertStringContainsString('Volunteering', $content);
    $this->assertStringContainsString('Set up', $content);
    $this->assertStringContainsString('Take down', $content);
    $this->assertStringContainsString('Pat', $content);
    $this->assertStringNotContainsString("You don't have permission to see any options", $content);
  }

  /**
   * The page is denied without the general "view membership options".
   */
  public function testPageDeniedWithoutViewMembershipOptions(): void {
    $this->setUpCurrentUser([], ['view field option 11 event 1']);

    $this->assertSame(403, $this->requestPage('1')->getStatusCode());
  }

  /**
   * Only paid, non-deleted members are listed, and totals count them.
   */
  public function testOnlyPaidActiveMembersAreListed(): void {
    $connection = Database::getConnection();
    foreach ([2 => ['is_paid' => 0], 3 => ['is_deleted' => 1]] as $mid => $overrides) {
      $connection->insert('conreg_members')
        ->fields($overrides + [
          'mid' => $mid,
          'eid' => 1,
          'language' => 'en',
          'join_date' => \Drupal::time()->getCurrentTime(),
          'update_date' => \Drupal::time()->getCurrentTime(),
          'first_name' => "Member $mid",
          'last_name' => 'Excluded',
          'email' => "member$mid@example.com",
          'is_paid' => 1,
          'is_deleted' => 0,
        ])
        ->execute();
      $connection->insert('conreg_member_options')
        ->fields(['mid' => $mid, 'optid' => 11, 'is_selected' => 1, 'option_detail' => ''])
        ->execute();
    }
    $this->setUpCurrentUser([], ['view membership options', 'view field option 11 event 1']);

    $form = $this->buildForm('1_11');

    $this->assertCount(1, $form['table']['#rows']);
    $this->assertSame('Pat', $form['table']['#rows'][0]['first_name']['data']);
    $this->assertSame(1, $form['table']['#footer'][0]['option_11']['data']);
  }

  /**
   * Option permissions are titled to sort together, with a description.
   */
  public function testOptionPermissionTitles(): void {
    $permissions = $this->container->get('user.permissions')->getPermissions();

    $permission = $permissions['view field option 12 event 1'];
    $this->assertSame('conreg', $permission['provider']);
    $this->assertSame(
      'Member option – <em class="placeholder">Test event</em>: <em class="placeholder">Take down</em>',
      (string) $permission['title'],
    );
    $this->assertStringContainsString('Also requires "View membership options"', (string) $permission['description']);

    // Sorted by title, the option permissions sit next to each other rather
    // than among ConReg's other "View …" permissions.
    $conreg = array_keys(array_filter($permissions, fn (array $p): bool => $p['provider'] === 'conreg'));
    $first = array_search('view field option 11 event 1', $conreg, TRUE);
    $this->assertSame('view field option 12 event 1', $conreg[$first + 1]);
  }

  /**
   * Option permissions sort by event, then option, and drop HTML tags.
   */
  public function testOptionPermissionsSortByEventWithoutHtml(): void {
    // A second event whose name sorts first, with an option whose title
    // sorts first but contains HTML.
    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Another event', 'is_open' => 1])
      ->execute();
    $this->config('conreg.settings.2')
      ->set('conreg_options.option_groups', ['1|checkboxes|confirm|Please confirm|0|1'])
      ->set('conreg_options.options', ['21|1|<a href="/coc">Code</a> of Conduct||0|0|Default|1|0|'])
      ->save();

    $permissions = $this->container->get('user.permissions')->getPermissions();
    $optionPermissions = array_keys(array_filter(
      $permissions,
      fn (string $name): bool => str_starts_with($name, 'view field option'),
      ARRAY_FILTER_USE_KEY,
    ));

    $this->assertSame([
      'view field option 21 event 2',
      'view field option 11 event 1',
      'view field option 12 event 1',
    ], $optionPermissions);
    $this->assertSame(
      'Member option – <em class="placeholder">Another event</em>: <em class="placeholder">Code of Conduct</em>',
      (string) $permissions['view field option 21 event 2']['title'],
    );
  }

  /**
   * Builds the Member Options form for event 1.
   *
   * @param string $selection
   *   The group or group_option to select, or '' for the default.
   *
   * @return array
   *   The built form.
   */
  protected function buildForm(string $selection = ''): array {
    return $this->container->get('form_builder')->getForm(MemberOptions::class, 1, $selection);
  }

  /**
   * Requests the Member Options page for event 1 through its route.
   *
   * @param string $selection
   *   The group or group_option to select.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function requestPage(string $selection): Response {
    $request = Request::create("/admin/members/options/1/$selection");
    $request->setSession(new Session(new MockArraySessionStorage()));
    return $this->container->get('http_kernel')->handle($request);
  }

}
