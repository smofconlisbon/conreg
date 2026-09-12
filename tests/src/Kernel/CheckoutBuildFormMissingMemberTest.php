<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Checkout;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Regression coverage for #3596624: checkout flow assumes a member is found.
 *
 * Checkout::buildForm() loads the payment's first line's member ID and
 * immediately calls Member::loadMember($mid) followed by
 * `$this->eid = $member->eid;` with no null check. If that member record
 * no longer exists - the exact scenario named in the issue - the request
 * throws a fatal error instead of showing the same "invalid payment
 * credentials" message used for other unusable payments.
 */
#[CoversClass(Checkout::class)]
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class CheckoutBuildFormMissingMemberTest extends KernelTestBase {

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
   * Set up a payment whose line references a member ID that doesn't exist.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'datetime']);
    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_options',
      'conreg_payments',
      'conreg_payment_lines',
    ]);
    $this->installConfig(['conreg']);

    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();

    // Deliberately no row in conreg_members: the payment line below points
    // at a member that has since been deleted (or never existed).
    Database::getConnection()->insert('conreg_payments')
      ->fields([
        'payid' => 1,
        'random_key' => 42,
      ])
      ->execute();
    Database::getConnection()->insert('conreg_payment_lines')
      ->fields([
        'payid' => 1,
        'mid' => 999,
        'payment_type' => 'member',
        'line_desc' => 'Adult membership',
        'amount' => 50.0,
      ])
      ->execute();
  }

  /**
   * Loading checkout for a payment whose member is missing must not error.
   *
   * Currently throws a fatal error inside Member::loadMember() (see
   * MemberLoadMemberTest::testLoadMemberReturnsNullForUnknownMid) instead of
   * returning a form - e.g. an error message - the way other unusable
   * payment/key combinations already do a few lines above. This test
   * deliberately doesn't assert what that fallback should say, since that's
   * a fix decision that hasn't been made yet; it only guards against the
   * fatal error itself.
   */
  public function testBuildFormHandlesMissingMemberGracefully(): void {
    $checkout = $this->container->get('class_resolver')->getInstanceFromDefinition(Checkout::class);
    $form_state = new FormState();

    $form = $checkout->buildForm([], $form_state, 1, 42);

    $this->assertIsArray($form);
  }

}
