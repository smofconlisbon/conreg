<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Registration;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the first member's email field when a logged-in user registers.
 *
 * Regression coverage for #3596611: the email field for the first member
 * was being built from two disconnected render elements (a manually
 * prefixed/suffixed label, plus a separate #type 'item' element), which
 * produced two disconnected DOM nodes for what should be a single labelled
 * item. It also used #type 'hidden', which renders an <input> that the
 * front end never reads.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class RegistrationEmailFieldTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'conreg',
  ];

  /**
   * Set up database tables and config for testing forms.
   */
  protected function setUp(): void {
    parent::setUp();

    // Required for #type = datetime.
    $this->installConfig(['system', 'datetime']);

    // Install the database schema for conreg.
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
   * Builds a Registration form instance for direct method calls.
   */
  protected function createRegistrationForm(): Registration {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(Registration::class);
  }

  /**
   * When logged in with no existing membership, member 1's email is fixed.
   *
   * It should be rendered as a single #type 'item' element (which already
   * generates its own linked label), with no separate label element
   * alongside it, and the underlying value should be carried by a #type
   * 'value' element rather than 'hidden', since nothing on the client needs
   * to read it back out of the DOM.
   */
  public function testLoggedInFirstMemberEmailIsSingleItemElement(): void {
    $this->container->get('current_user')->setAccount(new UserSession([
      'uid' => 2,
      'mail' => 'logged-in@example.com',
    ]));

    $formState = new FormState();
    $form = $this->createRegistrationForm()->buildForm([], $formState, 1);

    $member1 = $form['members']['member1'];

    // Only one extra render element (besides the value carrier) should
    // exist for the email - no separate label element alongside it.
    $this->assertArrayNotHasKey('email_label', $member1);

    $this->assertSame('value', $member1['email']['#type']);
    $this->assertSame('logged-in@example.com', $member1['email']['#value']);

    $this->assertSame('item', $member1['email_display']['#type']);
    $this->assertEquals('Email', (string) $member1['email_display']['#title']);
    $this->assertSame('logged-in@example.com', $member1['email_display']['#markup']);
  }

  /**
   * A logged-in user's fixed email still renders as a single DOM item.
   *
   * Confirms there is exactly one form-item wrapper for the email, instead
   * of the label and the item rendering as two disconnected wrappers.
   */
  public function testLoggedInFirstMemberEmailRendersAsSingleFormItem(): void {
    $this->container->get('current_user')->setAccount(new UserSession([
      'uid' => 2,
      'mail' => 'logged-in@example.com',
    ]));

    $formState = new FormState();
    $form = $this->createRegistrationForm()->buildForm([], $formState, 1);

    $renderable = $form['members']['member1']['email_display'];
    $html = (string) $this->container->get('renderer')->renderRoot($renderable);

    $this->assertStringContainsString('logged-in@example.com', $html);
    // A single #type 'item' element renders its own linked label, so the
    // "Email" label text should appear exactly once - not once from a
    // separate manually-built label element plus once again from the item.
    $this->assertSame(1, substr_count($html, 'Email'));
  }

  /**
   * A logged-in user who is already a member is unaffected.
   *
   * The email field falls back to the normal editable path, since
   * first_user_email is only set when the logged-in email has no existing
   * member record.
   */
  public function testLoggedInExistingMemberUsesEditableEmailField(): void {
    Database::getConnection()->insert('conreg_members')
      ->fields([
        'mid' => 1,
        'eid' => 1,
        'language' => 'en',
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'existing@example.com',
        'is_paid' => 1,
        'join_date' => \Drupal::time()->getCurrentTime(),
        'update_date' => \Drupal::time()->getCurrentTime(),
      ])
      ->execute();

    $this->container->get('current_user')->setAccount(new UserSession([
      'uid' => 2,
      'mail' => 'existing@example.com',
    ]));

    // Registration::buildForm() renders a "you already registered" link
    // through the renderer service when the current user has a paid
    // member, so building it needs an active render context (as it would
    // get from the normal page render pipeline).
    $formState = new FormState();
    $registration = $this->createRegistrationForm();
    $form = $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $registration->buildForm([], $formState, 1),
    );

    $member1 = $form['members']['member1'];
    $this->assertSame('email', $member1['email']['#type']);
    $this->assertArrayNotHasKey('email_display', $member1);
  }

}
