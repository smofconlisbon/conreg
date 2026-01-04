<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\conreg\Addons;
use Drupal\conreg\ConregConfig;
use Drupal\conreg\ConregOptions;
use Drupal\conreg\ConregStorage;
use Drupal\conreg\EventStorage;
use Drupal\conreg\Member;
use Drupal\conreg\Payment;
use Drupal\conreg\PaymentLine;
use Drupal\conreg\Upgrade;
use Drupal\conreg\UpgradeManager;
use Drupal\Core\DependencyInjection\AutowireTrait;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class FanTable extends FormBase {

  use AutowireTrait;

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
  public function getFormId() {
    return 'conreg_admin_fantable';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1, $lead_mid = 0) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);
    $event = EventStorage::load(['eid' => $eid]);

    // Get any existing form values for use in AJAX validation.
    $form_values = $form_state->getValues();

    $config = $this->config('conreg.settings.' . $eid);
    $types = ConregOptions::memberTypes($eid, $config);
    $upgrades = ConregOptions::memberUpgrades($eid, $config);
    $badgeTypes = ConregOptions::badgeTypes($eid, $config);
    $days = ConregOptions::days($eid, $config);

    $form_state->set('auto_approve', $config->get('payments.auto_approve'));

    // If action set, display either payment or check-in subpage.
    $action = $form_state->get('action');
    if (isset($action) && !empty($action)) {
      switch ($action) {
        case "payCash":
          $payid = $form_state->get("payid");
          $payment = Payment::load($payid);
          return $this->buildCashForm($eid, $payment, $config);
      }
    }

    if (isset($form_values['search'])) {
      $search = trim($form_values['search']);
    }

    $form = [
      '#title' => $this->t('@event_name Fan Table Console', ['@event_name' => $event['event_name']]),
      '#prefix' => '<div id="memberForm">',
      '#suffix' => '</div>',
    ];

    $form['summary'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Membership summary'),
    ];

    $this->memberAdminMemberListSummaryHorizontal($eid, $form['summary']);

    $form['search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Search registered members'),
    ];

    $headers = [
      'member_no' => ['data' => t('Member no'), 'field' => 'm.member_no', 'sort' => 'asc'],
      'first_name' => ['data' => t('First name'), 'field' => 'm.first_name'],
      'last_name' => ['data' => t('Last name'), 'field' => 'm.last_name'],
      'email' => ['data' => t('Email'), 'field' => 'm.email'],
      'badge_name' => ['data' => t('Badge name'), 'field' => 'm.badge_name'],
      'registered_by' => ['data' => t('Registered By'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'member_type' => ['data' => t('Member type'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'days' => ['data' => t('Days'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'badge_type' => ['data' => t('Badge type'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'comment' => ['data' => t('Comment'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'is_paid' => t('Paid'),
    ];

    $form['search']['search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom search term'),
      '#default_value' => trim($search ?? ''),
    ];

    $form['search']['search_button'] = [
      '#type' => 'button',
      '#value' => t('Search'),
      '#attributes' => ['id' => "searchBtn"],
      '#validate' => [],
      '#submit' => ['::search'],
      '#ajax' => [
        'wrapper' => 'memberForm',
        'callback' => [$this, 'updateDisplayCallback'],
      ],
    ];

    $form['search']['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ['id' => 'simple-conreg-admin-member-list'],
      '#empty' => t('No entries available.'),
      '#sticky' => TRUE,
    ];

    // Only check database if search filled in.
    if (!empty($search)) {
      $entries = ConregStorage::adminMemberCheckInListLoad($eid, $search);

      foreach ($entries as $entry) {
        $mid = $entry['mid'];
        // Sanitize each entry.
        $is_paid = $entry['is_paid'];
        $row = [];
        $row['mid'] = [
          '#markup' => Html::escape($entry['member_no']),
        ];
        $row['first_name'] = [
          '#markup' => Html::escape($entry['first_name']),
        ];
        $row['last_name'] = [
          '#markup' => Html::escape($entry['last_name']),
        ];
        $row['email'] = [
          '#markup' => Html::escape($entry['email']),
        ];
        $row['badge_name'] = [
          '#markup' => Html::escape($entry['badge_name']),
        ];
        $row['registered_by'] = [
          '#markup' => Html::escape($entry['registered_by']),
        ];
        $memberType = trim($entry['member_type']);
        if (isset($upgrades->options[$memberType][$entry['days']])) {
          $row['member_type'] = [
            '#type' => 'select',
            // '#title' => $fieldsetConfig->get('fields.membership_type_label'),
            '#options' => $upgrades->options[$memberType][$entry['days']],
            '#default_value' => 0,
            '#required' => TRUE,
          ];
        }
        else {
          $row['member_type'] = [
            '#markup' => Html::escape($types->types[$memberType]->name ?? $memberType),
          ];
        }

        if (!empty($entry['days'])) {
          $dayDescriptions = [];
          foreach (explode('|', $entry['days']) as $day) {
            $dayDescriptions[] = $days[$day] ?? $day;
          }
          $memberDays = implode(', ', $dayDescriptions);
        }
        else {
          $memberDays = '';
        }
        $row['days'] = [
          '#markup' => Html::escape($memberDays),
        ];
        $badgeType = trim($entry['badge_type']);
        $row['badge_type'] = [
          '#markup' => Html::escape($badgeTypes[$badgeType] ?? $badgeType),
        ];
        $row['comment'] = [
          '#markup' => Html::escape(trim(substr($entry['comment'], 0, 20))),
        ];
        $row['is_paid'] = [
          '#markup' => $is_paid ? $this->t('Yes') : $this->t('No'),
        ];

        $form['search']['table'][$mid] = $row;
      }
    }

    $form['unpaid'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Members awaiting payment'),
    ];

    $headers = [
      'first_name' => ['data' => t('First name'), 'field' => 'm.first_name'],
      'last_name' => ['data' => t('Last name'), 'field' => 'm.last_name'],
      'email' => ['data' => t('Email'), 'field' => 'm.email'],
      'badge_name' => ['data' => t('Badge name'), 'field' => 'm.badge_name'],
      'member_type' => ['data' => t('Member type'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'days' => ['data' => t('Days'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'price' => ['data' => t('Price'), 'field' => 'm.member_total'],
      'is_selected' => t('Select'),
    ];

    $form['unpaid']['unpaid'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ['id' => 'simple-conreg-admin-member-list'],
      '#empty' => t('No entries available.'),
      '#sticky' => TRUE,
    ];

    $entries = ConregStorage::adminMemberUnpaidListLoad($eid);

    foreach ($entries as $entry) {
      $mid = $entry['mid'];
      // Sanitize each entry.
      $is_paid = $entry['is_paid'];
      $row = [];
      $row['first_name'] = [
        '#markup' => Html::escape($entry['first_name']),
      ];
      $row['last_name'] = [
        '#markup' => Html::escape($entry['last_name']),
      ];
      $row['email'] = [
        '#markup' => Html::escape($entry['email']),
      ];
      $row['badge_name'] = [
        '#markup' => Html::escape($entry['badge_name']),
      ];
      $memberType = trim($entry['member_type']);
      $row['member_type'] = [
        '#markup' => Html::escape($types->types[$memberType]->name ?? $memberType),
      ];
      if (!empty($entry['days'])) {
        $dayDescriptions = [];
        foreach (explode('|', $entry['days']) as $day) {
          $dayDescriptions[] = $days[$day] ?? $day;
        }
        $memberDays = implode(', ', $dayDescriptions);
      }
      else {
        $memberDays = '';
      }
      $row['days'] = [
        '#markup' => Html::escape($memberDays),
      ];
      $row['price'] = [
        '#markup' => Html::escape($entry['member_total']),
      ];
      $row['is_selected'] = [
        '#type' => 'checkbox',
        '#title' => t('Select'),
        '#default_value' => 0,
      ];

      $form['unpaid']['unpaid'][$mid] = $row;
    }

    $form['add_members_button'] = [
      '#type' => 'submit',
      '#value' => t('Add Members'),
      '#attributes' => ['id' => "addBtn"],
      '#validate' => [],
      '#submit' => ['::addMembers'],
    ];

    $form['cash'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pay Cash'),
      '#submit' => [[$this, 'payCash']],
    ];

    $form['card'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pay Credit Card'),
      '#submit' => [[$this, 'payCard']],
    ];

    return $form;
  }

  /**
   * Add a summary by member type to render array.
   */
  public function memberAdminMemberListSummaryHorizontal($eid, &$content) {
    $types = ConregOptions::memberTypes($eid);
    $headers = [];
    $rows = [];
    $total = 0;
    foreach (ConregStorage::adminMemberSummaryLoad($eid) as $entry) {
      // Replace type code with description.
      $headers[] = isset($types->types[$entry['member_type']]) ? $types->types[$entry['member_type']]->name : $entry['member_type'];
      $rows[] = ['#markup' => $entry['num']];
      $total += $entry['num'];
    }
    // Add a row for the total.
    $headers[] = $this->t("Total");
    $rows[] = ['#markup' => $total];
    $content['summary'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#empty' => $this->t('No entries available.'),
      'rows' => $rows,
    ];

    return $content;
  }

  /**
   * Set up markup fields to display cash payment.
   */
  public function buildCashForm($eid, Payment $payment, $config) {

    $symbol = $config->get('payments.symbol');
    $form = [];
    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Please confirm cash received from:'),
      '#prefix' => '<div><h3>',
      '#suffix' => '</h3></div>',
    ];
    $total_price = 0;

    /** @var \Drupal\conreg\PaymentLine */
    foreach ($payment->paymentLines as $line) {
      $form['line' . $line->payLineId] = [
        '#type' => 'markup',
        '#markup' => $line->lineDesc,
        '#prefix' => '<div>',
        '#suffix' => '</div>',
      ];
      $total_price += $line->amount;
    }
    $form['payment_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Payment method'),
      '#options' => ConregOptions::paymentMethod(),
      '#default_value' => "Cash",
      '#required' => TRUE,
    ];
    $form['payment_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment reference'),
    ];
    $form['total'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Total to pay @symbol@total', ['@symbol' => $symbol, '@total' => $total_price]),
      '#prefix' => '<div><h4>',
      '#suffix' => '</h4></div>',
    ];
    $form['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm Cash Payment'),
      '#submit' => [[$this, 'confirmPayCash']],
    ];
    $form['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => [[$this, 'cancelAction']],
    ];
    return $form;
  }

  /**
   * Callback function for "display" drop down.
   */
  public function updateDisplayCallback(array $form, FormStateInterface $form_state) {
    // Form rebuilt with required number of members before callback.
    return $form;
  }

  /**
   * Callback for search for members.
   */
  public function search(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }

  /**
   * Callback for add members.
   */
  public function addMembers(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    // Redirect to payment form.
    $form_state->setRedirect('conreg_fantable_register',
      ['eid' => $eid]
    );
  }

  /**
   * If any member upgrades selected, save them so they can be charged.
   *
   * @param int $eid
   *   The event ID.
   * @param array $form_values
   *   Array of values returned by the form.
   * @param int|float $upgrade_price
   *   Returns the price of upgrades.
   * @param \Drupal\conreg\Payment $payment
   *   Payment object.
   */
  public function saveUpgrades($eid, $form_values, &$upgrade_price, Payment &$payment) {
    $mgr = new UpgradeManager($eid);

    if ($form_values["table"]) {
      foreach ($form_values["table"] as $mid => $memberRow) {
        $upgrade = new Upgrade($eid, $mid, $memberRow["member_type"]);
        // Only save upgrade if price is not null.
        if (isset($upgrade->upgradePrice)) {
          $mgr->Add($upgrade);
          $member = ConregStorage::load(['mid' => $mid]);
          $payment->add(new PaymentLine(
            $mid,
            'upgrade',
            t("Upgrade for @first_name @last_name", [
              '@first_name' => $member['first_name'],
              '@last_name' => $member['last_name'],
            ]),
            $upgrade->upgradePrice,
          ));
        }
      }
    }
    $upgrade_price = $mgr->getTotalPrice();
    $lead_mid = $mgr->saveUpgrades();
    return $lead_mid;
  }

  /**
   * Add payment lines for each member being paid for.
   */
  private function addMembersToPayment($eid, $form_values, Payment &$payment): array {
    $event = EventStorage::load(['eid' => $eid]);
    $config = ConregConfig::getConfig($eid);

    // Check for any unpaid members to add to payment.
    $toPay = [];
    foreach ($form_values['unpaid'] as $mid => $member) {
      if (isset($member['is_selected']) && $member['is_selected']) {
        $member = Member::loadMember($mid);
        $payment->add(new PaymentLine(
          $mid,
          'member',
          $this->t("Member registration to @event_name for @first_name @last_name",
          [
            '@event_name' => $event['event_name'],
            '@first_name' => $member->first_name,
            '@last_name' => $member->last_name,
          ]),
          $member->member_total,
        ));
        $addons = Addons::getMemberAddons($config, $mid);
        foreach ($addons as $addon) {
          // Add a payment line for the add-on.
          $payment->add(new PaymentLine(
            $mid,
            'addon',
            t("Add-on @add_on for @first_name @last_name",
            [
              '@add_on' => $addon->name,
              '@first_name' => $member->first_name,
              '@last_name' => $member->last_name,
            ]),
            $addon->amount,
          ));
        }
        $toPay[$mid] = $mid;
      }
    }
    return $toPay;
  }

  /**
   * Pay Cash button on main form clicked.
   */
  public function payCash(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $form_values = $form_state->getValues();

    $payment = new Payment();
    // Save any member upgrades.
    $lead_mid = $this->saveUpgrades($eid, $form_values, $upgrade_price, $payment);
    $toPay = $this->addMembersToPayment($eid, $form_values, $payment);

    // No need to proceed unless members have been selected.
    if (!empty($lead_mid) || count($toPay)) {
      $payid = $payment->save();
      $form_state->set('action', 'payCash');
      $form_state->set('toPay', $toPay);
      $form_state->set('payid', $payid);
      $form_state->set('lead_mid', $lead_mid);
    }
    $form_state->setRebuild();
  }

  /**
   * Confirm button on Pay Cash subform clicked.
   */
  public function confirmPayCash(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $payid = $form_state->get('payid');
    $form_values = $form_state->getValues();

    // Load the payment.
    $payment = Payment::load($payid);
    if (!is_null($payment)) {
      $payment->paidDate = time();
      $payment->paymentMethod = $form_values['payment_method'];
      $payment->paymentRef = $form_values['payment_id'];
      $payment->save();

      Addons::markPaid($payment->getId(), $form_values['payment_id']);

      // Process the payment lines.
      foreach ($payment->paymentLines as $line) {
        switch ($line->type) {
          case "member":
            $member = Member::loadMember($line->mid);
            if (is_object($member) && !$member->is_paid && !$member->is_deleted) {
              $member->is_paid = 1;
              $member->payment_id = $form_values['payment_id'];
              $member->payment_method = $form_values['payment_method'];
              if ($form_state->get('auto_approve')) {
                $member->is_approved = 1;
                $max_member = ConregStorage::loadMaxMemberNo($eid);
                $max_member++;
                $member->member_no = $max_member;
              }
              $member->saveMember();

              // If email address populated, send confirmation email.
              if (!empty($member->email)) {
                $this->sendConfirmationEmail((array) $member);
              }
            }
        }
      }
    }

    $payment_amount = 0;
    $lead_mid = $form_state->get('lead_mid');
    $toPay = $form_state->get('toPay');

    // Loop through selected members to get lead and total price.
    $memberPay = [];
    $upgradePay = [];
    foreach ($toPay as $mid) {
      // Check for member payments.
      if ($member = ConregStorage::load(['mid' => $mid, 'is_paid' => 0])) {
        // Make first member lead member.
        if ($lead_mid == 0) {
          $lead_mid = $mid;
        }
        $payment_amount += $member['member_total'];
        $memberPay[] = $mid;
      }
    }

    // Load upgrades into upgrade manager and process.
    $mgr = new UpgradeManager($eid);
    $mgr->loadUpgrades($lead_mid, FALSE);
    // Add total price of upgrades to total price of new members.
    $payment_amount += $mgr->getTotalPrice();
    $mgr->completeUpgrades($payment_amount, $form_values['payment_method'], $form_values['payment_id']);

    // Get next member number.
    $max_member_no = ConregStorage::loadMaxMemberNo($eid);
    // Loop again to update members.
    foreach ($memberPay as $mid) {
      $update = [
        'mid' => $mid,
        'lead_mid' => $lead_mid,
        'payment_amount' => $payment_amount,
        'payment_method' => $form_values['payment_method'],
        'payment_id' => $form_values['payment_id'],
        'is_paid' => 1,
        'member_no' => ++$max_member_no,
        'is_approved' => 1,
      ];
      ConregStorage::update($update);
    }
    // Loop again to update upgrades.
    foreach ($upgradePay as $mid => $upgid) {
    }
    $form_state->setRedirect('conreg_admin_fantable', ['eid' => $eid]);
  }

  /**
   * Pay by credit/debit card.
   */
  public function payCard(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $form_values = $form_state->getValues();

    $payment = new Payment();
    // Save any member upgrades.
    $lead_mid = $this->saveUpgrades($eid, $form_values, $upgrade_price, $payment);

    $toPay = $this->addMembersToPayment($eid, $form_values, $payment);

    // No need to proceed unless members have been selected.
    if (!empty($lead_mid) || count($toPay)) {
      $payid = $payment->save();
      // Redirect to payment form.
      $form_state->setRedirect(
        'conreg_fantable_checkout',
        ['payid' => $payid, 'key' => $payment->randomKey],
      );
    }
  }

  /**
   * Cancel payment when Cancel button pressed.
   */
  public function cancelAction(array &$form, FormStateInterface $form_state) {
    $form_state->set('action', '');
    $form_state->setRebuild();
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
  }

  /**
   * Send confirmation email on payment completion.
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

}
