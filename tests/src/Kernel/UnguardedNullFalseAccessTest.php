<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Checkout;
use Drupal\conreg\Form\Registration;
use Drupal\conreg\Payment;
use Drupal\conreg\PaymentLine;
use Drupal\conreg\Service\PaymentStorage;
use Drupal\conreg\Service\StripeServiceInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Regression coverage for #3596626: unguarded possibly-null/false access.
 *
 * Static analysis (PHPStan level 9, with the Drupal extension) found
 * several places in Registration and Checkout where a property is read, a
 * method called, or an array offset accessed on a value the type system
 * already knows could be NULL or FALSE at that point - `Payment::load()`
 * and `Payment::loadBySessionId()` are declared `Payment|null`,
 * `EventStorage::load()` is declared `array|false`, and
 * `FormStateInterface::getTriggeringElement()` is declared `?array`.
 *
 * Five of the seven flagged call sites are covered here, one test method
 * each. Two are deliberately not covered:
 * - `Registration::submitForm()` has the same unguarded
 *   `$this->eventStorage->load()` pattern as
 *   `Checkout::showThankYouPage()` (covered below), but reaching the
 *   affected line requires driving a full member-creation submission
 *   through to a saved member first - the underlying defect is the same
 *   one this file already demonstrates.
 * - `Registration::submitForm()` also calls `Member::newMember($entry)`
 *   (`Member|null`) without a null check, but `$entry` there is always a
 *   freshly built array literal, which `newMember()` can only turn into a
 *   NULL for a non-array (`FALSE`) argument - so that particular call can
 *   never actually return NULL. It's worth guarding for type-safety, but
 *   there's no reachable input that would make a regression test for it
 *   fail.
 */
#[CoversClass(Checkout::class)]
#[CoversClass(Registration::class)]
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class UnguardedNullFalseAccessTest extends KernelTestBase {

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
   * Install schema/config needed across all scenarios below.
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
      'conreg_payment_sessions',
      'conreg_upgrades',
    ]);
    $this->installConfig(['conreg']);

    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();
  }

  /**
   * Builds a Checkout form instance for direct method calls.
   */
  protected function createCheckoutForm(): Checkout {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(Checkout::class);
  }

  /**
   * Builds a Registration form instance for direct method calls.
   */
  protected function createRegistrationForm(): Registration {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(Registration::class);
  }

  /**
   * Runs $callback, turning "array offset on null/bool" warnings fatal.
   *
   * Indexing an array offset on NULL/FALSE is only a PHP warning, not a
   * fatal error, and Drupal's kernel test environment merely logs those
   * rather than failing the test - so without this, a test calling code
   * with this defect would silently "pass" despite the unguarded access
   * happening exactly as flagged by static analysis. This makes that
   * specific access visible as a thrown exception instead, while leaving
   * unrelated warnings (e.g. incidental test-environment noise from
   * downstream rendering) to be handled normally.
   */
  protected function callWithArrayOffsetWarningsAsExceptions(callable $callback): mixed {
    set_error_handler(static function (int $errno, string $errstr): bool {
      if (str_contains($errstr, 'array offset')) {
        throw new \ErrorException($errstr, 0, $errno);
      }
      return FALSE;
    }, E_WARNING);
    try {
      return $callback();
    }
    finally {
      restore_error_handler();
    }
  }

  /**
   * Regression test for an unguarded reload in buildForm().
   *
   * `buildForm()` loads the payment once to resolve the event/member, then
   * - after talking to Stripe via `processStripeMessages()` - reloads it a
   * second time ("Stripe messages processed, so we need to load the
   * payment again"). If the payment has since disappeared,
   * `Payment::load()` returns NULL, and it used to be passed unchecked to
   * `PricingService::recomputeForPayment(Payment $payment)`, which requires
   * a real `Payment` object.
   *
   * A mocked Stripe service simulates that disappearance as a side effect
   * of the `getEvents()` call that sits between the two loads.
   */
  public function testBuildFormThrowsWhenPaymentDisappearsDuringStripeSync(): void {
    Database::getConnection()->insert('conreg_payments')
      ->fields(['payid' => 1, 'random_key' => 42])
      ->execute();

    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->method('getEvents')->willReturnCallback(function () {
      // Simulate the payment vanishing between the first Payment::load()
      // in buildForm() and the reload that follows Stripe sync.
      Database::getConnection()->delete('conreg_payments')
        ->condition('payid', 1)
        ->execute();
      $events = new \stdClass();
      $events->data = [];
      return $events;
    });
    $this->container->set('conreg.stripe_service', $stripeService);

    $form = $this->createCheckoutForm()->buildForm([], new FormState(), 1, 42, '');

    $this->assertArrayHasKey('message', $form);
  }

  /**
   * Regression test for an unguarded event array read in showThankYouPage().
   *
   * `$this->eventStorage->load(['eid' => $eid])` returns `array|false`;
   * `showThankYouPage()` used to index `$event['event_name']` right after
   * loading it, with no check for FALSE.
   */
  public function testShowThankYouPageHandlesMissingEvent(): void {
    $payment = new Payment($this->container->get(PaymentStorage::class));
    $config = $this->container->get('config.factory')->get('conreg.settings.999999');
    $checkout = $this->createCheckoutForm();

    // Event ID 999999 has no matching row in conreg_events.
    $form = $this->callWithArrayOffsetWarningsAsExceptions(
      fn () => $checkout->showThankYouPage([], 999999, $config, $payment)
    );

    $this->assertArrayHasKey('message', $form);
  }

  /**
   * Regression test for an unguarded reload in processPaymentLine().
   *
   * The "member" case in `processStripeMessages()` checks
   * `if (!is_null($payment))` before using a `Payment::loadBySessionId()`
   * result. The "upgrade" case in `processPaymentLine()` calls the same
   * method but used to skip that check, passing the (possibly NULL)
   * result's properties straight into `UpgradeManager::completeUpgrades()`.
   *
   * Invoked directly via reflection since both methods are private and
   * reaching this branch through the public `buildForm()` entry point
   * would require simulating a second Stripe race on top of the first.
   */
  public function testProcessPaymentLineThrowsForUpgradeWithMissingPayment(): void {
    Database::getConnection()->insert('conreg_members')
      ->fields([
        'mid' => 1,
        'eid' => 1,
        'lead_mid' => 1,
        'language' => 'en',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane.doe@example.com',
        'is_deleted' => 0,
        'join_date' => \Drupal::time()->getCurrentTime(),
        'update_date' => \Drupal::time()->getCurrentTime(),
      ])
      ->execute();
    // A pending, unpaid upgrade so loadUpgrades() reports TRUE.
    Database::getConnection()->insert('conreg_upgrades')
      ->fields(['eid' => 1, 'mid' => 1, 'lead_mid' => 1, 'is_paid' => 0])
      ->execute();

    $checkout = $this->createCheckoutForm();
    (new \ReflectionProperty(Checkout::class, 'eid'))->setValue($checkout, 1);

    $line = new PaymentLine($this->container->get(PaymentStorage::class), 1, 'upgrade', 'Upgrade', 10.0);
    // No matching row in conreg_payment_sessions, so
    // Payment::loadBySessionId() returns NULL for this session.
    $session = (object) ['id' => 'sess_does_not_exist'];

    $method = new \ReflectionMethod(Checkout::class, 'processPaymentLine');

    // Must return quietly instead of crashing when the payment can't be
    // found for this session.
    $method->invoke($checkout, $line, $session);
    $this->addToAssertionCount(1);
  }

  /**
   * Regression test for an unguarded read in updateMemberPriceCallback().
   *
   * `FormStateInterface::getTriggeringElement()` is declared `?array`;
   * this callback used to index `['#name']` off it directly with no check
   * for NULL - reachable whenever `selected_class_changed` is set without
   * a triggering element also being present.
   */
  public function testUpdateMemberPriceCallbackHandlesNoTriggeringElement(): void {
    $registration = $this->createRegistrationForm();
    $formState = new FormState();
    $form = $registration->buildForm([], $formState, 1);
    // Deliberately no triggering element set on $formState.
    $formState->set('selected_class_changed', TRUE);

    // The rest of the callback renders the AJAX response's commands (e.g.
    // the price markup), which needs an active render context.
    $result = $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $this->callWithArrayOffsetWarningsAsExceptions(
        fn () => $registration->updateMemberPriceCallback($form, $formState)
      )
    );

    $this->assertInstanceOf(AjaxResponse::class, $result);
  }

  /**
   * Regression test for an unguarded read in updateMemberOptionFields().
   *
   * Same `?array`-returning `getTriggeringElement()` call as above, used
   * here to look up an AJAX callback by triggering element name.
   */
  public function testUpdateMemberOptionFieldsHandlesNoTriggeringElement(): void {
    $registration = $this->createRegistrationForm();
    $formState = new FormState();
    $form = $registration->buildForm([], $formState, 1);
    // Deliberately no triggering element set on $formState.
    $result = $this->callWithArrayOffsetWarningsAsExceptions(
      fn () => $registration->updateMemberOptionFields($form, $formState)
    );

    $this->assertSame([], $result);
  }

}
