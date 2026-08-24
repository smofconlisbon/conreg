<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\ConregEmailer;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore pi_3Nxyz

/**
 * Tests conreg tokens end-to-end through ConregEmailer::createEmail().
 *
 * This is the level at which container-wiring problems (e.g. a missing
 * module dependency needed to boot the service used to build the email)
 * actually surface, which neither the Unit test nor the thin Kernel
 * round-trip in TokenTest would catch.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregEmailerTokenTest extends KernelTestBase {

  /**
   * Modules to enable for this test.
   *
   * @var array
   */
  protected static $modules = ['system', 'user', 'datetime', 'key', 'conreg'];

  /**
   * Set up database tables and config for building an email.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'datetime']);

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
  }

  /**
   * The conreg:event-name token resolves in a real templated email.
   */
  public function testEventNameTokenResolvesInEmail(): void {
    $message = [];
    $params = [
      'eid' => 1,
      'to' => 'member@example.com',
      'subject' => 'Welcome to [conreg:event-name]',
      'body' => 'Thank you for joining [conreg:event-name].',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertSame('Welcome to Test event', $message['subject']);
    $this->assertStringContainsString('Thank you for joining Test event.', (string) $message['body'][0]);
  }

  /**
   * The conreg:member:first-name token resolves for the mid in $params.
   */
  public function testMemberFirstNameTokenResolvesInEmail(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
    ]);

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Welcome [conreg:member:first-name]',
      'body' => 'Hi [conreg:member:first-name], welcome to [conreg:event-name].',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertSame('Welcome Jane', $message['subject']);
    $this->assertStringContainsString('Hi Jane, welcome to Test event.', (string) $message['body'][0]);
  }

  /**
   * The members:all:first-name token resolves for the whole group.
   */
  public function testMembersAllFirstNameTokenResolvesInEmail(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 1,
      'first_name' => 'John',
      'last_name' => 'Bloggs',
      'email' => 'john.bloggs@example.com',
    ]);

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Group booking',
      'body' => 'Members: [conreg:members:all:first-name].',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertStringContainsString('Members: Jane and John.', (string) $message['body'][0]);
  }

  /**
   * Member-type and country codes are resolved to labels, price to currency.
   *
   * These values come from MemberPresenter::present(), not from
   * ConregTokenHooks itself (see its MEMBER_FIELDS docblock) - this
   * proves the resolution actually works against real DB-stored codes,
   * not just hand-built Unit test fixtures.
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

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => '[conreg:member:member-type] membership, [conreg:member:country], [conreg:member:member-price].',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertStringContainsString('Adult membership, Ireland, €50.', (string) $message['body'][0]);
  }

  /**
   * Payment-id resolves as a plain raw column, not a computed one.
   *
   * Unlike its member-total/payment-amount siblings in MEMBER_FIELDS.
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

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => 'Your payment reference is [conreg:member:payment-id].',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertStringContainsString('Your payment reference is pi_3Nxyz.', (string) $message['body'][0]);
  }

  /**
   * Status and badge tokens all resolve via MemberPresenter::present().
   *
   * Display, communication method, approval/payment, and badge number.
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

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => 'Badge [conreg:member:member-no]: [conreg:member:display], [conreg:member:communication-method], approved [conreg:member:is-approved], paid [conreg:member:is-paid].',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertStringContainsString('Badge A0007: Full name and member name, Electronic, approved Yes, paid No.', (string) $message['body'][0]);
  }

  /**
   * The login URL is a real routed, absolute URL for the mid in $params.
   */
  public function testMemberLoginUrlTokenResolvesInEmail(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
    ]);

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => 'Log in here: [conreg:member:login-url]',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertMatchesRegularExpression('#Log in here: https?://[^\s]+/members/login/1/[^/\s]+/\d+#', (string) $message['body'][0]);
  }

  /**
   * An existing, still-valid login_exp_date (a string from the DB) is kept.
   *
   * MemberStorage::load() returns DB columns as strings. When a member
   * already has a login_exp_date more than 24 hours in the future,
   * MemberPresenter::updateLoginExpiryDate() must return that value
   * unchanged as an int, not the raw string - a real bug that only
   * showed up on a member's second confirmation email, since a fresh
   * expiry (computed from int arithmetic) never exercised this path.
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

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => 'Log in here: [conreg:member:login-url]',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertStringContainsString((string) $futureExpiry, (string) $message['body'][0]);
  }

  /**
   * Payment-amount is the group total, broadcast to every member.
   *
   * Every member's price plus any paid add-ons, summed once - not a
   * per-member figure.
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

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => 'Your total: [conreg:member:member-total]. Group total: [conreg:member:payment-amount].',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertStringContainsString('Your total: €50. Group total: €95.', (string) $message['body'][0]);
  }

  /**
   * Lead-key/lead-email resolve to the group leader, not the addressee.
   *
   * When mid != lead_mid, i.e. the addressed member isn't the group
   * leader themselves.
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

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 2,
      'to' => 'jack.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => 'Group contact: [conreg:member:lead-email] ([conreg:member:lead-key]).',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertStringContainsString('Group contact: lead@example.com (123456).', (string) $message['body'][0]);
  }

  /**
   * The member-details token renders a real HTML table for the group.
   *
   * With member-type/country/communication-method resolved to labels.
   * This also exercises the communication-method row, which never
   * rendered in the old ConregTokens table at all due to a label-key
   * typo ('communications_method' vs the real 'communication_method')
   * fixed during this extraction - see
   * MemberDetailsFormatter::CONFIRM_FIELDS.
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

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => '[conreg:member-details]',
    ];

    ConregEmailer::createEmail($message, $params);

    $body = (string) $message['body'][0];
    $this->assertStringContainsString('<table>', $body);
    $this->assertStringContainsString('Member 1', $body);
    $this->assertStringContainsString('Member 2', $body);
    $this->assertStringContainsString('<td>A0007</td>', $body);
    $this->assertStringContainsString('<td>Adult</td>', $body);
    $this->assertStringContainsString('<td>Ireland</td>', $body);
    $this->assertStringContainsString('How would you like to receive progress reports?', $body);
    $this->assertStringContainsString('<td>Electronic</td>', $body);
    $this->assertStringContainsString('Total amount paid', $body);
    $this->assertStringContainsString('€95', $body);
  }

  /**
   * Member-details renders as plain text when so configured.
   *
   * A plain-text block, no HTML markup, when the event's confirmation is
   * configured as plain text.
   */
  public function testMemberDetailsTokenRendersPlainTextWhenConfigured(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'member_price' => 50,
    ]);
    $this->container->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('confirmation.format_html', 0)
      ->save();

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => 1,
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => '[conreg:member-details]',
    ];

    ConregEmailer::createEmail($message, $params);

    $body = (string) $message['body'][0];
    $this->assertStringNotContainsString('<table>', $body);
    $this->assertStringContainsString('Member 1', $body);
    $this->assertStringContainsString('Member Total', $body);
  }

  /**
   * An array of mids combines separate registration groups into one email.
   *
   * The admin "email member" form does this when the same person has more
   * than one separate registration under the same email address. Only the
   * first mid gets login/primary-member treatment; every group's members
   * still appear in the flat members list.
   */
  public function testArrayOfMidsCombinesSeparateGroups(): void {
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

    $message = [];
    $params = [
      'eid' => 1,
      'mid' => [1, 2],
      'to' => 'jane.doe@example.com',
      'subject' => 'Booking confirmed',
      'body' => 'Members: [conreg:members:all:first-name]. Primary: [conreg:member:first-name].',
    ];

    ConregEmailer::createEmail($message, $params);

    $this->assertStringContainsString('Members: Jane and Janet. Primary: Jane.', (string) $message['body'][0]);
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
