<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\easy_email\Entity\EasyEmailInterface;
use Drupal\Core\Database\Database;
use Drupal\easy_email\Entity\EasyEmailType;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore Nxyz

/**
 * Tests conreg tokens resolving through Easy Email's real token pipeline.
 *
 * Easy Email's EmailTokenEvaluator only ever passes `['easy_email' =>
 * $email]` to \Drupal::token()->replace() - this proves the bridge in
 * EmailTokenContext/ConregTokenHooks actually derives `event`/`members`
 * from an easy_email entity's field_conreg_eid/field_conreg_mid the same
 * way production traffic will, rather than relying on the old direct
 * `['event' => ..., 'members' => ...]` data ConregEmailer used to build.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class EmailTokenContextTest extends KernelTestBase {

  /**
   * Modules to enable for this test.
   *
   * @var array
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
   * Set up database tables and config for building an email.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('easy_email');
    $this->installEntitySchema('file');
    $this->installConfig(['system', 'datetime', 'filter', 'easy_email']);

    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
    ]);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();

    EasyEmailType::create([
      'id' => 'conreg_registration_test',
      'label' => 'ConReg registration (test)',
    ])->save();
  }

  /**
   * The conreg:event-name token resolves via an easy_email entity.
   */
  public function testEventNameTokenResolvesThroughEasyEmail(): void {
    $email = $this->createConregEmail(1, []);

    $result = \Drupal::token()->replace('Welcome to [conreg:event-name]', ['easy_email' => $email]);

    $this->assertSame('Welcome to Test event', $result);
  }

  /**
   * The conreg:member:first-name token resolves for the mid on the entity.
   */
  public function testMemberFirstNameTokenResolvesThroughEasyEmail(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = \Drupal::token()->replace(
      'Hi [conreg:member:first-name], welcome to [conreg:event-name].',
      ['easy_email' => $email],
    );

    $this->assertSame('Hi Jane, welcome to Test event.', $result);
  }

  /**
   * An array of mids combines separate registration groups into one email.
   */
  public function testArrayOfMidsCombinesSeparateGroupsThroughEasyEmail(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 2,
      'first_name' => 'Janet',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
    ]);

    $email = $this->createConregEmail(1, [1, 2]);

    $result = \Drupal::token()->replace(
      'Members: [conreg:members:all:first-name]. Primary: [conreg:member:first-name].',
      ['easy_email' => $email],
    );

    $this->assertSame('Members: Jane and Janet. Primary: Jane.', $result);
  }

  /**
   * The bridge is only used when the caller hasn't already supplied data.
   *
   * If $data['event']/$data['members'] are already set (e.g. a future
   * direct caller), the entity-derived values must not override them.
   */
  public function testExplicitEventAndMembersDataTakesPrecedence(): void {
    $email = $this->createConregEmail(1, []);

    $result = \Drupal::token()->replace(
      '[conreg:event-name]',
      ['easy_email' => $email, 'event' => ['name' => 'Explicit event'], 'members' => []],
    );

    $this->assertSame('Explicit event', $result);
  }

  /**
   * Member-type and country codes are resolved to labels, price to currency.
   *
   * These values come from MemberPresenter::present(), not from
   * ConregTokenHooks itself - this proves the resolution actually works
   * against real DB-stored codes, not just hand-built Unit test fixtures.
   */
  public function testMemberTypeAndCountryTokensResolveToLabels(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'member_type' => 'A',
      'country' => 'IE',
      'member_price' => 50,
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = \Drupal::token()->replace(
      '[conreg:member:member-type] membership, [conreg:member:country], [conreg:member:member-price].',
      ['easy_email' => $email],
    );

    $this->assertSame('Adult membership, Ireland, €50.00.', $result);
  }

  /**
   * Payment-id resolves as a plain raw column, not a computed one.
   */
  public function testPaymentIdTokenResolvesRawColumn(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'payment_id' => 'pi_3Nxyz',
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = \Drupal::token()->replace(
      'Your payment reference is [conreg:member:payment-id].',
      ['easy_email' => $email],
    );

    $this->assertSame('Your payment reference is pi_3Nxyz.', $result);
  }

  /**
   * Status and badge tokens all resolve via MemberPresenter::present().
   */
  public function testMemberStatusAndBadgeTokensResolveToLabels(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'display' => 'F',
      'communication_method' => 'E',
      'is_approved' => 1,
      'is_paid' => 0,
      'badge_type' => 'A',
      'member_no' => 7,
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = \Drupal::token()->replace(
      'Badge [conreg:member:member-no]: [conreg:member:display], [conreg:member:communication-method], approved [conreg:member:is-approved], paid [conreg:member:is-paid].',
      ['easy_email' => $email],
    );

    $this->assertSame('Badge A0007: Full name and member name, Electronic, approved Yes, paid No.', $result);
  }

  /**
   * The login URL is a real routed, absolute URL for the mid on the entity.
   */
  public function testMemberLoginUrlTokenResolvesInEmail(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = \Drupal::token()->replace('Log in here: [conreg:member:login-url]', ['easy_email' => $email]);

    $this->assertMatchesRegularExpression('#Log in here: https?://[^\s]+/members/login/1/[^/\s]+/\d+#', $result);
  }

  /**
   * An existing, still-valid login_exp_date (a string from the DB) is kept.
   */
  public function testMemberLoginUrlReusesExistingFutureExpiry(): void {
    $futureExpiry = \Drupal::time()->getCurrentTime() + 2 * 86400;
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'random_key' => 123456,
      'login_exp_date' => $futureExpiry,
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = \Drupal::token()->replace('Log in here: [conreg:member:login-url]', ['easy_email' => $email]);

    $this->assertStringContainsString((string) $futureExpiry, $result);
  }

  /**
   * Payment-amount is the group total, broadcast to every member.
   */
  public function testPaymentAmountTokenSumsWholeGroup(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'member_price' => 50,
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 1,
      'first_name' => 'Jack',
      'last_name' => 'Doe',
      'email' => 'jack.doe@example.com',
      'member_price' => 45,
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = \Drupal::token()->replace(
      'Your total: [conreg:member:member-total]. Group total: [conreg:member:payment-amount].',
      ['easy_email' => $email],
    );

    $this->assertSame('Your total: €50. Group total: €95.', $result);
  }

  /**
   * Lead-key/lead-email resolve to the group leader, not the addressee.
   */
  public function testLeadKeyAndLeadEmailResolveForNonLeadMember(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'lead@example.com',
      'random_key' => 123456,
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 1,
      'first_name' => 'Jack',
      'last_name' => 'Doe',
      'email' => 'jack.doe@example.com',
    ]);

    $email = $this->createConregEmail(1, [2]);

    $result = \Drupal::token()->replace(
      'Group contact: [conreg:member:lead-email] ([conreg:member:lead-key]).',
      ['easy_email' => $email],
    );

    $this->assertSame('Group contact: lead@example.com (123456).', $result);
  }

  /**
   * The member-details token renders a real HTML table for the group.
   */
  public function testMemberDetailsTokenRendersRealTable(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'member_type' => 'A',
      'country' => 'IE',
      'display' => 'F',
      'communication_method' => 'E',
      'member_price' => 50,
      'badge_type' => 'A',
      'member_no' => 7,
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 1,
      'first_name' => 'Jack',
      'last_name' => 'Doe',
      'email' => 'jack.doe@example.com',
      'member_type' => 'A',
      'country' => 'IE',
      'display' => 'F',
      'communication_method' => 'E',
      'member_price' => 45,
      'badge_type' => 'A',
      'member_no' => 8,
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = (string) \Drupal::token()->replace('[conreg:member-details]', ['easy_email' => $email]);

    $this->assertStringContainsString('<table style=', $result);
    $this->assertStringContainsString('Member 1', $result);
    $this->assertStringContainsString('Member 2', $result);
    $this->assertStringContainsString('>A0007</td>', $result);
    $this->assertStringContainsString('>Adult</td>', $result);
    $this->assertStringContainsString('>Ireland</td>', $result);
    $this->assertStringContainsString('How would you like to receive progress reports?', $result);
    $this->assertStringContainsString('>Electronic</td>', $result);
    $this->assertStringContainsString('Total amount paid', $result);
    $this->assertStringContainsString('€95', $result);
  }

  /**
   * Members who joined on different dates each get their own heading/table.
   *
   * Regression test: members combined from separate registrations (e.g.
   * the "email a member" admin form merging several registrations under
   * one email address) can have different join dates - they used to all
   * get listed under whichever member happened to be first, with no
   * indication the rest joined on a different day.
   */
  public function testMemberDetailsGroupsByRegistrationDate(): void {
    $day1 = mktime(10, 0, 0, 7, 1, 2026);
    $day2 = mktime(15, 30, 0, 8, 13, 2026);
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'join_date' => $day1,
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 1,
      'first_name' => 'Jack',
      'last_name' => 'Doe',
      'email' => 'jack.doe@example.com',
      'join_date' => $day1,
    ]);
    $this->createTestMember([
      'mid' => 3,
      'lead_mid' => 1,
      'first_name' => 'Janet',
      'last_name' => 'Doe',
      'email' => 'janet.doe@example.com',
      'join_date' => $day2,
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = (string) \Drupal::token()->replace('[conreg:member-details]', ['easy_email' => $email]);

    $this->assertSame(2, substr_count($result, '<table'), 'One table per distinct join date.');
    $this->assertSame(2, substr_count($result, '<h3>'), 'One heading per distinct join date.');
    $this->assertStringContainsString('Registered on Wed, 1 Jul 2026', $result);
    $this->assertStringContainsString('Registered on Thu, 13 Aug 2026', $result);
    // No time of day in the heading.
    $this->assertDoesNotMatchRegularExpression('/Registered on[^<]*\d{1,2}:\d{2}/', $result);
    // Numbering continues across the break rather than restarting.
    $this->assertStringContainsString('Member 1', $result);
    $this->assertStringContainsString('Member 2', $result);
    $this->assertStringContainsString('Member 3', $result);
    $this->assertGreaterThan(
      strpos($result, 'Member 2'),
      strpos($result, 'Registered on Thu, 13 Aug 2026'),
      'The second heading appears after "Member 2", not before it.',
    );
  }

  /**
   * Member-details renders as plain text when the "plain_text" option is set.
   *
   * Mirrors how the plain-text alternative body is generated (Easy Email's
   * generateBodyPlain, or a plain-text-only template) rather than the old
   * per-event confirmation.format_html toggle, which no longer exists.
   */
  public function testMemberDetailsTokenRendersPlainTextWhenOptionSet(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'member_price' => 50,
    ]);

    $email = $this->createConregEmail(1, [1]);

    $result = \Drupal::token()->replace(
      '[conreg:member-details]',
      ['easy_email' => $email],
      ['plain_text' => TRUE],
    );

    $this->assertStringNotContainsString('<table>', $result);
    $this->assertStringContainsString('Member 1', $result);
    $this->assertStringContainsString('Member Total', $result);
  }

  /**
   * Creates and saves a `conreg_registration_test` easy_email entity.
   */
  protected function createConregEmail(int $eid, array $mids): EasyEmailInterface {
    $email = \Drupal::entityTypeManager()->getStorage('easy_email')->create([
      'type' => 'conreg_registration_test',
      'field_conreg_eid' => $eid,
      'field_conreg_mid' => $mids,
    ]);
    $email->save();
    return $email;
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

    return (int) Database::getConnection()
      ->insert('conreg_members')
      ->fields($fields)
      ->execute();
  }

}
