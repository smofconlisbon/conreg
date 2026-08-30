<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit;

use Drupal\Component\Render\MarkupInterface;
use Drupal\conreg\Hook\ConregTokenHooks;
use Drupal\conreg\Member;
use Drupal\conreg\Service\EmailTokenContext;
use Drupal\conreg\Service\MemberDetailsFormatter;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests token replacement in ConregTokenHooks::tokens().
 */
#[CoversClass(ConregTokenHooks::class)]
#[Group('conreg')]
class TokenTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   *
   * The multi-member case in formatList() calls
   * BubbleableMetadata::addCacheContexts(), which asserts its contexts
   * are valid via the cache_contexts_manager service. Stub just that one
   * service so the assertion can run without a full container boot.
   */
  protected function setUp(): void {
    parent::setUp();

    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Each conreg token resolves correctly within a full sentence.
   */
  #[DataProvider('tokenProvider')]
  public function testTokenReplacement(string $token_name, string $input, string $expected): void {
    $hooks = $this->createHooks();
    $original = "[conreg:$token_name]";

    $replacements = $hooks->tokens('conreg', [$token_name => $original], $this->tokenData(), [], new BubbleableMetadata());

    $this->assertSame($expected, strtr($input, $replacements));
  }

  /**
   * Token name, input sentence, and the sentence after substitution.
   */
  public static function tokenProvider(): array {
    return [
      'event-name' => [
        'event-name',
        'Thank you for joining [conreg:event-name]',
        'Thank you for joining An amazing event',
      ],
      'event-email' => [
        'event-email',
        'Contact us at [conreg:event-email]',
        'Contact us at contact@example.com',
      ],
      'member (falls back to the lead of members)' => [
        'member:first-name',
        'Hi [conreg:member:first-name]',
        'Hi Jane',
      ],
      'members:1 (the lead member)' => [
        'members:1:first-name',
        'Hi [conreg:members:1:first-name]',
        'Hi Jane',
      ],
      'members:2 (last name field)' => [
        'members:2:last-name',
        'Hi [conreg:members:2:last-name]',
        'Hi Bloggs',
      ],
      'member:full-name (computed, not a real field)' => [
        'member:full-name',
        'Hi [conreg:member:full-name]',
        'Hi Jane Doe',
      ],
      'member:badge-name' => [
        'member:badge-name',
        'Badge: [conreg:member:badge-name]',
        'Badge: JaneD',
      ],
      'member:email' => [
        'member:email',
        'Reply to [conreg:member:email]',
        'Reply to jane.doe@example.com',
      ],
      'member:street' => [
        'member:street',
        'Address: [conreg:member:street]',
        'Address: 1 Main Street',
      ],
      'member:city' => [
        'member:city',
        'City: [conreg:member:city]',
        'City: Dublin',
      ],
      'member:country (already resolved to a label upstream)' => [
        'member:country',
        'Country: [conreg:member:country]',
        'Country: Ireland',
      ],
      'member:member-type (already resolved to a label upstream)' => [
        'member:member-type',
        'Type: [conreg:member:member-type]',
        'Type: Adult',
      ],
      'member:member-price (already currency-formatted upstream)' => [
        'member:member-price',
        'Price: [conreg:member:member-price]',
        'Price: €50',
      ],
      'member:member-total (already currency-formatted upstream)' => [
        'member:member-total',
        'Total: [conreg:member:member-total]',
        'Total: €65',
      ],
      'member:payment-amount (group total, already currency-formatted upstream)' => [
        'member:payment-amount',
        'Group total: [conreg:member:payment-amount]',
        'Group total: €95',
      ],
      'member:payment-id (raw column, not computed)' => [
        'member:payment-id',
        'Payment reference: [conreg:member:payment-id]',
        'Payment reference: pi_abc123',
      ],
      'member:member-no (already formatted upstream)' => [
        'member:member-no',
        'Badge no: [conreg:member:member-no]',
        'Badge no: A0007',
      ],
      'member:display (already resolved to a label upstream)' => [
        'member:display',
        'Display: [conreg:member:display]',
        'Display: Full name and member name',
      ],
      'member:communication-method (already resolved to a label upstream)' => [
        'member:communication-method',
        'Contact by: [conreg:member:communication-method]',
        'Contact by: Electronic',
      ],
      'member:is-approved (already rendered upstream)' => [
        'member:is-approved',
        'Approved: [conreg:member:is-approved]',
        'Approved: Yes',
      ],
      'member:is-paid (already rendered upstream)' => [
        'member:is-paid',
        'Paid: [conreg:member:is-paid]',
        'Paid: No',
      ],
      'member:is-deleted (already rendered upstream)' => [
        'member:is-deleted',
        'Deleted: [conreg:member:is-deleted]',
        'Deleted: No',
      ],
      'member:login-url (already built upstream)' => [
        'member:login-url',
        'Log in: [conreg:member:login-url]',
        'Log in: https://example.com/members/login/1/abc123/999',
      ],
      'member:login-expiry-medium (already formatted upstream)' => [
        'member:login-expiry-medium',
        'Link expires [conreg:member:login-expiry-medium]',
        'Link expires Fri, 01/01/2027',
      ],
      'member:lead-key (already resolved upstream)' => [
        'member:lead-key',
        'Lead key: [conreg:member:lead-key]',
        'Lead key: lead-key-123',
      ],
      'member:lead-email (already resolved upstream)' => [
        'member:lead-email',
        'Lead email: [conreg:member:lead-email]',
        'Lead email: lead@example.com',
      ],
    ];
  }

  /**
   * An index beyond the group leaves the token unresolved.
   */
  public function testMembersOutOfRangeIndexIsLeftUnresolved(): void {
    $hooks = $this->createHooks();
    $original = '[conreg:members:5:first-name]';

    $replacements = $hooks->tokens('conreg', ['members:5:first-name' => $original], $this->tokenData(), [], new BubbleableMetadata());

    $this->assertSame([], $replacements);
  }

  /**
   * An explicit $data['member'] takes precedence over the members list.
   */
  public function testMemberPrefersExplicitMemberOverMembersList(): void {
    $hooks = $this->createHooks();
    $original = '[conreg:member:first-name]';
    $data = $this->tokenData();
    $data['member'] = Member::newMember(['first_name' => 'Alex', 'last_name' => 'Smith']);

    $replacements = $hooks->tokens('conreg', ['member:first-name' => $original], $data, [], new BubbleableMetadata());

    $this->assertSame([$original => 'Alex'], $replacements);
  }

  /**
   * The members:all:* token joins every member's field into one sentence.
   */
  #[DataProvider('membersAllProvider')]
  public function testMembersAllFormatsAsList(array $first_names, string $expected): void {
    $hooks = $this->createHooks();
    $original = '[conreg:members:all:first-name]';
    $data = [
      'members' => array_map(
        fn (string $first_name) => Member::newMember(['first_name' => $first_name]),
        $first_names,
      ),
    ];

    $replacements = $hooks->tokens('conreg', ['members:all:first-name' => $original], $data, [], new BubbleableMetadata());

    $this->assertSame($expected, $replacements[$original]);
  }

  /**
   * Member counts and the series each should format to.
   */
  public static function membersAllProvider(): array {
    return [
      'no members' => [[], ''],
      'one member' => [['James'], 'James'],
      'two members' => [['James', 'Jack'], 'James and Jack'],
      'three members' => [['James', 'Jack', 'Jane'], 'James, Jack, and Jane'],
    ];
  }

  /**
   * Builds a ConregTokenHooks with everything mocked, no container needed.
   *
   * The translation stub covers @-placeholder strings like formatList()'s
   * series joins. MemberDetailsFormatter is mocked too - its real
   * DB/config-dependent behavior is covered by the Kernel integration
   * test instead.
   */
  protected function createHooks(?MemberDetailsFormatter $formatter = NULL): ConregTokenHooks {
    $hooks = new ConregTokenHooks(
      $formatter ?? $this->createMock(MemberDetailsFormatter::class),
      $this->createMock(EmailTokenContext::class),
    );
    $hooks->setStringTranslation($this->getStringTranslationStub());
    return $hooks;
  }

  /**
   * Builds the token $data array the way ConregEmailer assembles it.
   */
  protected function tokenData(): array {
    $lead = Member::newMember([
      'mid' => 1,
      'eid' => 1,
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'badge_name' => 'JaneD',
      'email' => 'jane.doe@example.com',
      'street' => '1 Main Street',
      'city' => 'Dublin',
      // country/member_type/member_price/member_total/payment_amount/
      // member_no/display/communication_method/is_approved/is_paid/
      // is_deleted are already resolved to labels, currency, or rendered
      // strings by the time ConregEmailer builds this data (see the
      // MEMBER_FIELDS docblock in ConregTokenHooks).
      'country' => 'Ireland',
      'member_type' => 'Adult',
      'member_price' => '€50',
      'member_total' => '€65',
      'payment_amount' => '€95',
      'payment_id' => 'pi_abc123',
      'member_no' => 'A0007',
      'display' => 'Full name and member name',
      'communication_method' => 'Electronic',
      'is_approved' => 'Yes',
      'is_paid' => 'No',
      'is_deleted' => 'No',
      // login_url/login_expiry_medium/lead_key/lead_email are only ever
      // attached to the primary member (see
      // ConregEmailer::buildMembersTokenData()).
      'login_url' => 'https://example.com/members/login/1/abc123/999',
      'login_expiry_medium' => 'Fri, 01/01/2027',
      'lead_key' => 'lead-key-123',
      'lead_email' => 'lead@example.com',
    ]);
    $second = Member::newMember([
      'mid' => 2,
      'eid' => 1,
      'first_name' => 'John',
      'last_name' => 'Bloggs',
      'email' => 'john.bloggs@example.com',
    ]);

    return [
      'event' => [
        'eid' => 1,
        'name' => 'An amazing event',
        'email' => 'contact@example.com',
      ],
      'members' => [$lead, $second],
    ];
  }

  /**
   * The member-details token delegates to MemberDetailsFormatter.
   *
   * It picks html vs plain from the plain_text option - the
   * DB/config-dependent table building itself is covered by the Kernel
   * integration test instead.
   */
  public function testMemberDetailsSelectsHtmlOrPlainFromOptions(): void {
    $data = $this->tokenData();

    $formatter = $this->createMock(MemberDetailsFormatter::class);
    $formatter->expects($this->exactly(2))
      ->method('build')
      ->with(1, $this->callback(fn (array $members) => count($members) === 2 && $members[0]['first_name'] === 'Jane'))
      ->willReturn(['html' => '<table>HTML</table>', 'plain' => 'PLAIN']);
    $hooks = $this->createHooks($formatter);
    $original = '[conreg:member-details]';

    $html = $hooks->tokens('conreg', ['member-details' => $original], $data, [], new BubbleableMetadata());
    $plain = $hooks->tokens('conreg', ['member-details' => $original], $data, ['plain_text' => TRUE], new BubbleableMetadata());

    // The HTML variant comes back marked safe (Markup), not a plain
    // string, so Token::replace() doesn't re-escape the table markup.
    $this->assertInstanceOf(MarkupInterface::class, $html[$original]);
    $this->assertSame('<table>HTML</table>', (string) $html[$original]);
    $this->assertSame('PLAIN', $plain[$original]);
  }

  /**
   * With no event/members context, member-details is left unresolved.
   */
  public function testMemberDetailsWithoutContextIsLeftUnresolved(): void {
    $hooks = $this->createHooks();
    $original = '[conreg:member-details]';

    $replacements = $hooks->tokens('conreg', ['member-details' => $original], ['event' => []], [], new BubbleableMetadata());

    $this->assertSame([], $replacements);
  }

}
