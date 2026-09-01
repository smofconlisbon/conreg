<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Admin\MemberEmail;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\easy_email\Entity\EasyEmail;
use Drupal\easy_email\Entity\EasyEmailType;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests MemberEmail's combining of separate registration groups on submit.
 *
 * MemberEmail::buildForm() looks up every other *paid* member sharing the
 * addressee's email address but belonging to a *different* registration
 * group (a different lead_mid), and folds them into one combined send
 * (field_conreg_mid holds every mid) rather than sending one email per
 * group. This is a pre-existing feature (previously implemented against
 * ConregEmailer's raw $params, now against the easy_email entity's
 * multi-value field_conreg_mid field) - these tests lock down its
 * inclusion/exclusion rules and prove the combined email actually resolves
 * tokens for every included member, not just the addressee.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MemberEmailFormSubmitTest extends KernelTestBase {

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
   * Set up database tables, config, and a template for the form.
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
      'subject' => 'Welcome',
      'bodyHtml' => ['value' => '<p>[conreg:members:all:first-name]</p>', 'format' => 'full_html'],
      'generateBodyPlain' => FALSE,
    ])->save();
  }

  /**
   * Two separate paid registrations under the same email are combined.
   */
  public function testSubmitCombinesSeparateRegistrationGroupsIntoOneEmail(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'email' => 'shared@example.com',
      'is_paid' => 1,
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 2,
      'first_name' => 'Janet',
      'email' => 'shared@example.com',
      'is_paid' => 1,
    ]);

    $this->submitMemberEmailForm(1, 1);

    $emails = \Drupal::entityTypeManager()->getStorage('easy_email')->loadMultiple();
    $this->assertCount(1, $emails, 'Exactly one email was sent, not one per registration group.');

    /** @var \Drupal\easy_email\Entity\EasyEmail */
    $email = array_first($emails);
    $this->assertSame(['shared@example.com'], $email->getRecipientAddresses());
    $mids = array_map(fn (array $item) => (int) $item['value'], $email->get('field_conreg_mid')->getValue());
    sort($mids);
    $this->assertSame([1, 2], $mids);
  }

  /**
   * An unpaid member sharing the same email is not folded into the combine.
   */
  public function testSubmitExcludesUnpaidMembersFromCombine(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'email' => 'shared@example.com',
      'is_paid' => 1,
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 2,
      'first_name' => 'Janet',
      'email' => 'shared@example.com',
      'is_paid' => 0,
    ]);

    $this->submitMemberEmailForm(1, 1);

    $email = $this->assertSingleSentEmail();
    $mids = array_map(fn (array $item) => (int) $item['value'], $email->get('field_conreg_mid')->getValue());
    $this->assertSame([1], $mids, 'The unpaid member (mid 2) must not be combined in.');
  }

  /**
   * A paid member with a different email address is not folded in.
   */
  public function testSubmitExcludesMembersWithDifferentEmail(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'email' => 'shared@example.com',
      'is_paid' => 1,
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 2,
      'first_name' => 'Janet',
      'email' => 'other@example.com',
      'is_paid' => 1,
    ]);

    $this->submitMemberEmailForm(1, 1);

    $email = $this->assertSingleSentEmail();
    $mids = array_map(fn (array $item) => (int) $item['value'], $email->get('field_conreg_mid')->getValue());
    $this->assertSame([1], $mids, 'The member with a different email address (mid 2) must not be combined in.');
  }

  /**
   * The combined email's tokens resolve every included member, not just one.
   *
   * Locks down that field_conreg_mid being set correctly actually feeds
   * through EmailTokenContext (e.g. its per-entity cache keyed by the
   * saved entity id) into the resolved body seen by every recipient.
   */
  public function testCombinedEmailTokensResolveAllMembers(): void {
    $this->createTestMember([
      'mid' => 1,
      'lead_mid' => 1,
      'first_name' => 'Jane',
      'email' => 'shared@example.com',
      'is_paid' => 1,
    ]);
    $this->createTestMember([
      'mid' => 2,
      'lead_mid' => 2,
      'first_name' => 'Janet',
      'email' => 'shared@example.com',
      'is_paid' => 1,
    ]);

    $this->submitMemberEmailForm(1, 1);

    $email = $this->assertSingleSentEmail();
    $resolved = \Drupal::token()->replace($email->getHtmlBody()['value'], ['easy_email' => $email]);
    $this->assertStringContainsString('Jane', $resolved);
    $this->assertStringContainsString('Janet', $resolved);
  }

  /**
   * Builds and submits MemberEmail for the given event/mid, as an admin would.
   */
  protected function submitMemberEmailForm(int $eid, int $mid): void {
    $formObject = MemberEmail::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, $eid, $mid);

    $formState->setValues([
      'email' => [
        'message' => [
          'subject' => 'Welcome',
          'body' => [
            'value' => '<p>[conreg:members:all:first-name]</p>',
            'format' => 'full_html',
          ],
        ],
      ],
    ]);
    $formObject->submitForm($form, $formState);
  }

  /**
   * Asserts exactly one email was sent and returns it.
   *
   * @return \Drupal\easy_email\Entity\EasyEmail
   *   The first easy email.
   */
  protected function assertSingleSentEmail(): EasyEmail {
    $emails = \Drupal::entityTypeManager()->getStorage('easy_email')->loadMultiple();
    $this->assertCount(1, $emails);
    return $this->getFirstEasyEmail($emails);
  }

  /**
   * Gets the first element in an array of Easy Emails.
   *
   * @param array $emails
   *   Array of easy emails.
   *
   * @return \Drupal\easy_email\Entity\EasyEmail
   *   The first easy email.
   */
  protected function getFirstEasyEmail(array $emails): EasyEmail {
    return array_first($emails);
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
      'is_paid' => 1,
      'is_deleted' => 0,
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
