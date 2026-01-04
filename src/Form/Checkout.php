<?php

namespace Drupal\conreg\Form;

use Stripe\Event;
use Stripe\Stripe;
use Drupal\conreg\Addons;
use Drupal\conreg\ConregOptions;
use Drupal\conreg\ConregStorage;
use Drupal\conreg\EventStorage;
use Drupal\conreg\Member;
use Drupal\conreg\Payment;
use Drupal\conreg\PaymentStorage;
use Drupal\conreg\UpgradeManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Stripe\Checkout\Session;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class Checkout extends FormBase {

  /**
   * The event ID.
   *
   * @var int
   */
  private int $eid;

  /**
   * If true, members auto approved on payment.
   *
   * @var bool
   */
  private bool $autoApprove;

  /**
   * Constructs a new EmailExampleGetFormPage.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_payment';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $payid = NULL, $key = NULL, $return = '') {

    $form_state->set('return', $return);

    // Load payment details.
    if (is_numeric($payid) && is_numeric($key) && PaymentStorage::checkPaymentKey($payid, $key)) {
      $payment = Payment::load($payid);
    }
    else {
      $form['message'] = [
        '#markup' => $this->t('Invalid payment credentials. Please return to <a href="@url">registration page</a> and complete membership details.', ["@url" => "/members/register"]),
      ];
      return $form;
    }

    // Get event ID to fetch Stripe keys. If payment has MID, get event from
    // member. If not, assume event 1 (will come up with a better long term
    // solution).
    if (isset($payment) && isset($payment->paymentLines[0]) && !empty($payment->paymentLines[0]->mid)) {
      $mid = $payment->paymentLines[0]->mid;
      $member = Member::loadMember($mid);
      $this->eid = $member->eid;
      if (empty(trim($member->email)) && $mid != $member->lead_mid) {
        $lead_member = Member::loadMember($member->lead_mid);
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
    Stripe::setApiKey($config->get('payments.private_key'));

    $this->processStripeMessages($config);

    // Stripe messages processed, so we need to load the payment again.
    $payment = Payment::load($payid);

    // Check if payment date populated. If so, payment is complete.
    if ($payment->paidDate) {
      return $this->showThankYouPage($form, $this->eid, $config, $payment);
    }

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
        $this->processWithoutPayment($line);
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

      // Set up Stripe Session.
      $session = Session::create([
        'payment_method_types' => $types,
        'mode' => Session::MODE_PAYMENT,
        'customer_email' => $email,
        'line_items' => $items,
        'success_url' => $success,
        'cancel_url' => $cancel,
      ]);

      // Update the payment with the session ID.
      $payment->sessionId = $session->id;
      $payment->save();

      $from['#title'] = $this->t("Transferring to Stripe");

      // Attach the Javascript library and set up parameters.
      $form['#attached'] = [
        'library' => ['conreg/conreg_checkout'],
        'drupalSettings' => [
          'conreg' => [
            'checkout' => [
              'public_key' => $config->get('payments.public_key'),
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
  public function showThankYouPage($form, $eid, $config, $payment) {
    $event = EventStorage::load(['eid' => $eid]);
    $find = ['[reference]', '[event_name]'];
    $replace = [$payment->paymentRef, $event['event_name']];

    $message = str_replace($find,
      $replace,
      $config->get('thanks.thank_you_message'));
    $format = $config->get('thanks.thank_you_format');

    $form['#title'] = $config->get('thanks.title');
    $form['message'] = [
      '#markup' => check_markup($message, $format),
    ];

    return $form;
  }

  /**
   * Function to process payments coming back from Stripe.
   */
  private function processStripeMessages($config) {
    // Check events on Stripe.
    $events = Event::all([
      'type' => 'checkout.session.completed',
      'created' => [
        // Check for events created in the last 24 hours.
        'gte' => time() - 24 * 60 * 60,
      ],
    ]);

    // Loop through received events and mark payments complete.
    foreach ($events->data as $event) {
      $session = ((object) $event)->data->object;
      // Update the payment record.
      $payment = Payment::loadBySessionId($session->id);
      if (!is_null($payment)) {
        // Only update payment if not already paid.
        if (empty($payment->paidDate)) {
          $payment->paidDate = time();
          $payment->paymentMethod = "Stripe";
          $payment->paymentRef = $session->payment_intent;
          $payment->save();
        }

        Addons::markPaid($payment->getId(), $session->payment_intent);

        // Process the payment lines.
        foreach ($payment->paymentLines as $line) {
          $this->processPaymentLine($line, $session);
        }
      }
    }
  }

  /**
   * Process a line of payment information from Stripe.
   */
  private function processPaymentLine($line, $session) {
    switch ($line->type) {
      case "member":
        // Only update member if not already paid.
        $member = Member::loadMember($line->mid);
        if (is_object($member) && !$member->is_paid && !$member->is_deleted) {
          $member->is_paid = 1;
          if ($this->autoApprove) {
            $member->is_approved = 1;
            $max_member = ConregStorage::loadMaxMemberNo($this->eid);
            $max_member++;
            $member->member_no = $max_member;
          }
          $member->payment_id = $session->payment_intent;
          $member->payment_method = 'Stripe';
          $member->saveMember();

          // If email address populated, send confirmation email.
          if (!empty($member->email)) {
            $this->sendConfirmationEmail((array) $member);
          }
        }
        break;

      case "upgrade":
        $member = Member::loadMember($line->mid);
        if (isset($member) && is_object($member) && !$member->is_deleted) {
          $mgr = new UpgradeManager($member->eid);
          if ($mgr->loadUpgrades($member->mid, 0)) {
            $payment = Payment::loadBySessionId($session->id);
            $mgr->completeUpgrades($payment->paymentAmount, $payment->paymentMethod, $payment->paymentRef);
          }
        }
        break;
    }
  }

  /**
   * If no charge for payment line, just marked paid.
   */
  private function processWithoutPayment($line) {
    switch ($line->type) {
      case "member":
        // Only update member if not already paid.
        $member = Member::loadMember($line->mid);
        if (is_object($member) && !$member->is_paid && !$member->is_deleted) {
          $member->is_paid = 1;
          if ($this->autoApprove) {
            $member->is_approved = 1;
            $max_member = ConregStorage::loadMaxMemberNo($this->eid);
            $max_member++;
            $member->member_no = $max_member;
          }
          $member->payment_id = 'N/A';
          $member->payment_method = 'Free';
          $member->saveMember();

          // If email address populated, send confirmation email.
          if (!empty($member->email)) {
            $this->sendConfirmationEmail((array) $member);
          }
        }
        break;
    }
  }

  /**
   * Send the email to confirm completion.
   */
  private function sendConfirmationEmail($member) {
    $config = $this->config('conreg.settings.' . $member['eid']);
    $types = ConregOptions::memberTypes($member['eid'], $config);

    // Set up parameters for receipt email.
    $params = ['eid' => $member['eid'], 'mid' => $member['mid']];
    if ($types->types[$member['member_type']]->confirmation->override ?: FALSE) {
      $params['subject'] = $types->types[$member['member_type']]->confirmation->template_subject;
      $params['body'] = $types->types[$member['member_type']]->confirmation->template_body;
      $params['body_format'] = $types->types[$member['member_type']]->confirmation->template_format;
    }
    else {
      $params['subject'] = $config->get('confirmation.template_subject');
      $params['body'] = $config->get('confirmation.template_body');
      $params['body_format'] = $config->get('confirmation.template_format');
    }
    $params['include_private'] = TRUE;
    $module = "conreg";
    $key = "template";
    $to = $member["email"];
    $language_code = $this->languageManager->getDefaultLanguage()->getId();
    // Send confirmation email to member.
    if (!empty($member["email"])) {
      $this->mailManager->mail($module, $key, $to, $language_code, $params);
    }

    // If copy_us checkbox checked, send a copy to us.
    if ($config->get('confirmation.copy_us')) {
      $params['subject'] = $config->get('confirmation.notification_subject');
      $params['include_private'] = FALSE;
      $to = $config->get('confirmation.from_email');
      $this->mailManager->mail($module, $key, $to, $language_code, $params);
    }

    // If copy email to field provided, send an extra copy to us.
    if (!empty($config->get('confirmation.copy_email_to'))) {
      $params['subject'] = $config->get('confirmation.notification_subject');
      $params['include_private'] = FALSE;
      $to = $config->get('confirmation.copy_email_to');
      $this->mailManager->mail($module, $key, $to, $language_code, $params);
    }
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
