<?php

namespace Drupal\conreg\Form;

use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\Payment;
use Drupal\Component\Utility\Html;
use Drupal\conreg\Service\MemberPresenter;
use Drupal\conreg\Service\PaymentCompletionService;
use Drupal\conreg\Service\PaymentStorage;
use Drupal\conreg\Service\PricingServiceInterface;
use Drupal\conreg\Service\StripeServiceInterface;
use Drupal\conreg\Member;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class Checkout extends FormBase {

  use AutowireTrait;

  /**
   * The event ID.
   *
   * @var int
   */
  protected int $eid;

  /**
   * If true, members auto approved on payment.
   *
   * @var bool
   */
  protected bool $autoApprove;

  /**
   * Constructs a new Checkout form.
   *
   * @param \Drupal\conreg\Service\StripeServiceInterface $stripeService
   *   The Stripe service.
   * @param \Drupal\conreg\Service\EventStorage $eventStorage
   *   The event storage service.
   * @param \Drupal\conreg\Service\PaymentStorage $paymentStorage
   *   The payment storage service.
   * @param \Drupal\conreg\Service\PaymentCompletionService $paymentCompletion
   *   Marks a payment (and its members/upgrades) complete once Stripe
   *   confirms its session was paid.
   * @param \Drupal\conreg\Service\MemberPresenter $memberPresenter
   *   The member presenter service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\conreg\Service\PricingServiceInterface $pricingService
   *   The pricing service.
   */
  public function __construct(
    protected StripeServiceInterface $stripeService,
    protected EventStorage $eventStorage,
    protected PaymentStorage $paymentStorage,
    protected PaymentCompletionService $paymentCompletion,
    protected MemberPresenter $memberPresenter,
    protected Token $token,
    protected PricingServiceInterface $pricingService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_payment';
  }

  /**
   * If payment not found, build a page with the error.
   *
   * @return array
   *   The render array with the message.
   */
  public function invalidCredentials(): array {
    $form['message'] = [
      '#markup' => $this->t('Invalid payment credentials. Please return to <a href="@url">registration page</a> and complete membership details.', ["@url" => "/members/register"]),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $payid = NULL, $key = NULL, $return = '') {

    $form_state->set('return', $return);

    // Load payment details.
    if (is_numeric($payid) && is_numeric($key) && $this->paymentStorage->checkPaymentKey($payid, $key)) {
      $payment = Payment::load($payid);
    }
    else {
      return $this->invalidCredentials();
    }

    // Get event ID to fetch Stripe keys. If payment has MID, get event from
    // member. If not, assume event 1 (will come up with a better long term
    // solution).
    if (isset($payment) && isset($payment->paymentLines[0]) && !empty($payment->paymentLines[0]->mid)) {
      $mid = $payment->paymentLines[0]->mid;
      $member = Member::loadMember($mid);
      if (is_null($member)) {
        return $this->invalidCredentials();
      }
      $this->eid = $member->eid;
      if (empty(trim($member->email)) && $mid != $member->lead_mid) {
        $lead_member = Member::loadMember($member->lead_mid);
        if (is_null($lead_member)) {
          return $this->invalidCredentials();
        }
        $email = $lead_member->email;
      }
      else {
        $email = $member->email;
      }
      $form_state->set('mid', $mid);
    }
    else {
      $this->eid = 1;
      $email = '';
    }
    $config = $this->config('conreg.settings.' . $this->eid);

    $form_state->set('eid', $this->eid);

    $this->autoApprove = $config->get('payments.auto_approve') ?: FALSE;

    // Set Stripe secret key from event settings.
    $this->stripeService->setApiKey($this->eid);

    // If a Stripe session already exists for this payment (e.g. the member
    // is returning from Stripe's hosted checkout), check its status
    // directly rather than scanning Stripe's global events list - that
    // avoids depending on a shared, paginated, time-windowed feed, and lets
    // an still-open session be reused below instead of creating a second
    // one for the same payment.
    $existingSession = NULL;
    if (empty($payment->paidDate)) {
      $sessionIds = $this->paymentStorage->loadSessionIds($payment->payId);
      if (!empty($sessionIds)) {
        $existingSession = $this->stripeService->retrieveSession($sessionIds[0]);
        if ($existingSession && $this->paymentCompletion->isSessionPaid($existingSession)) {
          $this->paymentCompletion->markSessionComplete($payment, $existingSession, $this->eid, $this->autoApprove);
          $payment = Payment::load($payid);
          if (is_null($payment)) {
            // Should never happen, but if payment not valid, show warning.
            return $this->invalidCredentials();
          }
          $existingSession = NULL;
        }
      }
    }

    // Check if payment date populated. If so, payment is complete.
    if ($payment->paidDate) {
      return $this->showThankYouPage($form, $this->eid, $config, $payment);
    }

    // Recompute prices from current member/config data before charging,
    // in case an admin edited a member's pricing-relevant details (e.g.
    // their type) after registration but before payment completed.
    $this->pricingService->recomputeForPayment($payment);

    // Set up payment lines on Stripe.
    $items = [];
    $total = 0;
    foreach ($payment->paymentLines as $line) {
      // Only add member to payment if price greater than zero...
      if ($line->amount > 0) {
        $items[] = [
          'price_data' => [
            'currency' => $config->get('payments.currency'),
            'product_data' => [
              'name' => $line->lineDesc,
            ],
            'unit_amount' => $line->amount * 100,
          ],
          'quantity' => 1,
        ];
      }
      else {
        $this->paymentCompletion->processWithoutPayment($line, $this->eid, $this->autoApprove);
      }
      $total += $line->amount;
    }

    // Only redirect to Stripe if something to pay for...
    if ($total > 0) {
      // Set up return URLs.
      $success = Url::fromRoute("conreg_checkout", [
        "payid" => $payment->payId,
        "key" => $payment->randomKey,
      ], ['absolute' => TRUE])->toString();
      $cancel = Url::fromRoute("conreg_register", ["eid" => $this->eid], ['absolute' => TRUE])->toString();

      $types = empty($config->get('payments.types')) ? ['card'] : explode('|', $config->get('payments.types'));

      // Reuse a still-open session for this exact amount, rather than
      // always minting a new one - otherwise a double-click, a page
      // refresh during the "Transferring to Stripe" wait, or reopening the
      // payment link in a second tab would each create a separate live
      // Stripe session for the same payment, risking the member paying
      // more than once. If the recomputed total has changed since that
      // session was created (e.g. an admin edited pricing-relevant
      // details in between), fall through and create a fresh one instead.
      $session = NULL;
      if ($existingSession && $this->paymentCompletion->isSessionOpen($existingSession)) {
        $expectedAmountTotal = (int) round($total * 100);
        if ((int) ($existingSession->amount_total ?? -1) === $expectedAmountTotal) {
          $session = $existingSession;
        }
      }

      if (is_null($session)) {
        // Set up Stripe Session.
        $session = $this->stripeService->createCheckoutSession([
          'payment_method_types' => $types,
          'mode' => 'payment',
          'customer_email' => $email,
          'line_items' => $items,
          'success_url' => $success,
          'cancel_url' => $cancel,
        ]);

        // Update the payment with the session ID.
        $payment->sessionId = $session->id;
        $payment->save();
      }

      // A test double for the Stripe service can request a local mock
      // checkout page instead of the client-side hand-off below, since that
      // requires an actual browser talking to Stripe's hosted checkout
      // page. The mock page shows the amount due and a "Pay now" button
      // that submits straight back to the success URL - the next pass
      // through this route will find the (also mocked) completed session
      // and show the thank-you page, exactly as it would after a real
      // Stripe redirect. This gives tests a page-then-button-click flow to
      // drive, similar to the real Stripe-hosted checkout.
      if ($this->stripeService->useMockCheckoutPage()) {
        $form['#title'] = $this->t('Mock Stripe Checkout');
        $form['mock_checkout'] = [
          '#markup' => Markup::create(
            '<div class="mock-stripe-checkout">'
            . '<p id="mock-stripe-total">' . $this->t('Total: @symbol@amount', [
              '@symbol' => $config->get('payments.symbol'),
              '@amount' => number_format($total, 2),
            ]) . '</p>'
            . '<form method="get" action="' . Html::escape($success) . '">'
            . '<button type="submit" id="mock-pay-now">' . $this->t('Pay now') . '</button>'
            . '</form>'
            . '</div>'
          ),
        ];
        return $form;
      }

      $form['#title'] = $this->t("Transferring to Stripe");

      // Attach the Javascript library and set up parameters.
      $form['#attached'] = [
        'library' => ['conreg/conreg_checkout'],
        'drupalSettings' => [
          'conreg' => [
            'checkout' => [
              'public_key' => $this->stripeService->resolveKey($config->get('payments.public_key')),
              'session_id' => $session->id,
            ],
          ],
        ],
      ];

      $form['security_message'] = [
        '#markup' => $this->t('You will be transferred to Stripe to securely accept your payment. Your browser will return after payment processed.'),
        '#prefix' => '<div>',
        '#suffix' => '</div>',
      ];
    }
    // Nothing to pay for, so just mark paid.
    else {
      $payment->paidDate = time();
      $payment->paymentMethod = "Free";
      $payment->paymentRef = "N/A";
      $payment->save();
      return $this->showThankYouPage($form, $this->eid, $config, $payment);
    }

    return $form;
  }

  /**
   * Display.
   */
  public function showThankYouPage(array $form, int $eid, ImmutableConfig $config, Payment $payment) {
    $event = $this->eventStorage->load(['eid' => $eid]);
    if (!$event) {
      // Event should be valid, but if not, we should display warning.
      return $this->invalidCredentials();
    }
    $drupalTokenData = [
      'event' => [
        'eid' => $eid,
        'name' => $event['event_name'],
        'email' => $config->get('confirmation.from_email'),
      ],
    ];

    // If the payment is tied to a member, resolve their fields too (same
    // presenter ConregEmailer uses), so e.g. [conreg:member:payment-id]
    // works here exactly as it does in the confirmation email.
    $mid = $payment->paymentLines[0]->mid ?? NULL;
    if (!empty($mid)) {
      $members = $this->memberPresenter->loadGroup($eid, (int) $mid);
      $this->memberPresenter->present($eid, $members);
      $drupalTokenData['members'] = array_map(fn (array $member) => Member::newMember($member), $members);
    }

    $bubbleable_metadata = new BubbleableMetadata();
    $message = $this->token->replace($config->get('thanks.thank_you_message'), $drupalTokenData, [], $bubbleable_metadata);
    $format = $config->get('thanks.thank_you_format');

    $form['#title'] = $config->get('thanks.title');
    $form['message'] = [
      '#type' => 'processed_text',
      '#text' => $message,
      '#format' => $format,
    ];
    $bubbleable_metadata->applyTo($form);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->eid = $form_state->get('eid');
    $mid = $form_state->get('mid');
    $return = $form_state->get('return');

    switch ($return) {
      case 'checkin':
        // Redirect to check-in page.
        $form_state->setRedirect('conreg_admin_checkin', [
          'eid' => $this->eid,
          'lead_mid' => $mid,
        ]);
        break;

      case 'fantable':
        // Redirect to fan table page.
        $form_state->setRedirect('conreg_admin_fantable', [
          'eid' => $this->eid,
          'lead_mid' => $mid,
        ]);
        break;

      case 'portal':
        // Redirect to portal.
        $form_state->setRedirect('conreg_portal', ['eid' => $this->eid]);
        break;

      default:
        // Redirect to payment form.
        $form_state->setRedirect('conreg_thanks', ['eid' => $this->eid]);
    }
  }

}
