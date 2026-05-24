<?php

namespace Drupal\conreg\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\ConregOptions;
use Drupal\conreg\Payment;
use Drupal\conreg\PaymentLine;
use Drupal\conreg\Service\AddonStorage;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Service\UpgradeStorage;
use Drupal\conreg\Upgrade;
use Drupal\conreg\UpgradeManager;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class MemberPortal extends FormBase {

  use AutowireTrait;

  /**
   * Construct the form.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\conreg\Service\AddonStorage $addonStorage
   *   The addon storage service.
   * @param \Drupal\conreg\Service\UpgradeStorage $upgradeStorage
   *   The upgrade storage service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected AddonStorage $addonStorage,
    protected UpgradeStorage $upgradeStorage,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_member_portal';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    $config = $this->config('conreg.settings.' . $eid);
    $types = ConregOptions::memberTypes($eid, $config);
    $upgrades = ConregOptions::memberUpgrades($eid, $config);
    $days = ConregOptions::days($eid, $config);

    $email = $this->currentUser()->getEmail();

    $form = [
      '#attached' => [
        'library' => ['conreg/conreg_tables'],
      ],
      '#prefix' => '<div id="memberForm">',
      '#suffix' => '</div>',
    ];

    $headers = [
      'member_no' => ['data' => $this->t('Member no'), 'field' => 'm.member_no', 'sort' => 'asc'],
      'first_name' => ['data' => $this->t('First name'), 'field' => 'm.first_name'],
      'last_name' => ['data' => $this->t('Last name'), 'field' => 'm.last_name'],
      'email' => ['data' => $this->t('Email'), 'field' => 'm.email'],
      'badge_name' => ['data' => $this->t('Badge name'), 'field' => 'm.badge_name'],
      'member_type' => ['data' => $this->t('Member type'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'days' => ['data' => $this->t('Days'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'is_paid' => $this->t('Paid'),
      'link' => $this->t('Edit'),
    ];

    $entries = $this->memberStorage->adminMemberPortalListLoad($eid, $email, TRUE);

    // Only show table if members found.
    if (count($entries)) {
      $form['table'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#attributes' => ['id' => 'conreg-admin-member-list'],
        '#empty' => $this->t('No entries available.'),
        '#sticky' => TRUE,
      ];

      foreach ($entries as $entry) {
        $mid = $entry['mid'];
        // Sanitize each entry.
        $is_paid = $entry['is_paid'];
        $row = [];
        $row['mid'] = [
          '#markup' => Html::escape($entry['member_no'] ?: ""),
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
            '#markup' => Html::escape($types->types[$memberType]->name ?: $memberType),
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
        $row['is_paid'] = [
          '#markup' => $is_paid ? $this->t('Yes') : $this->t('No'),
        ];
        $row['link'] = [
          '#type' => 'dropbutton',
          '#links' => [
            'edit_button' => [
              'title' => $this->t('Edit'),
              'url' => Url::fromRoute('conreg_portal_edit', ['eid' => $eid, 'mid' => $mid]),
            ],
          ],
        ];

        $form['table'][$mid] = $row;
      }
    }

    // Load all members for email address (paid and unpaid)...
    $entries = $this->memberStorage->adminMemberPortalListLoad($eid, $email);

    // Default to keep unpaid grid hidden.
    $display_unpaid = FALSE;
    $headers = [
      'payment_type' => ['data' => $this->t('Payment Type')],
      'name' => ['data' => $this->t('Name'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'email' => ['data' => $this->t('Email'), 'field' => 'm.email', 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'detail' => ['data' => $this->t('Detail')],
      'price' => ['data' => $this->t('Price'), 'field' => 'm.member_total'],
    ];

    $unpaid = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ['id' => 'conreg-admin-member-list'],
      '#empty' => $this->t('No entries available.'),
      '#sticky' => TRUE,
    ];

    foreach ($entries as $entry) {
      $mid = $entry['mid'];
      // Sanitize each entry.
      $is_paid = $entry['is_paid'];

      if (!$is_paid) {
        $row = [];
        $row['type'] = ['#markup' => $this->t('Member')];
        $row['name'] = ['#markup' => Html::escape($entry['first_name'] . ' ' . $entry['last_name'])];
        $row['email'] = ['#markup' => Html::escape($entry['email'])];
        $memberType = $types->types[trim($entry['member_type'])]->name ?? trim($entry['member_type']);
        $row['member_type'] = ['#markup' => Html::escape($memberType)];
        $row['price'] = ['#markup' => Html::escape($entry['member_price'])];
        $unpaid[$mid] = $row;
        $display_unpaid = TRUE;
      }

      foreach ($this->addonStorage->loadAll(['mid' => $mid, 'is_paid' => 0]) as $addon) {
        $row = [];
        $row['type'] = ['#markup' => $this->t('Add-on')];
        $row['name'] = ['#markup' => Html::escape($entry['first_name'] . ' ' . $entry['last_name'])];
        $row['email'] = ['#markup' => Html::escape($entry['email'])];
        $row['member_type'] = ['#markup' => Html::escape($addon['addon_name'] . (isset($addon['addon_option']) ? ' - ' . $addon['addon_option'] : ''))];
        $row['price'] = ['#markup' => Html::escape($addon['addon_amount'])];
        $unpaid['addon_' . $addon['addonid']] = $row;
        $display_unpaid = TRUE;
      }
    }

    // Only show table if unpaid members found.
    if ($display_unpaid) {
      $form['unpaid_title'] = [
        '#markup' => $this->t('Unpaid Members and Add-ons'),
        '#prefix' => '<h2>',
        '#suffix' => '</h2>',
      ];

      $form['unpaid'] = $unpaid;
    }

    /*
    $form['add_members_button'] = array(
    '#type' => 'submit',
    '#value' => t('Add Members'),
    '#attributes' => array('id' => "addBtn"),
    '#validate' => array(),
    '#submit' => array('::addMembers'),
    );
     */
    $form['card'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pay Credit Card'),
      '#submit' => [[$this, 'payCard']],
    ];

    return $form;
  }

  /**
   * Callback function for "display" drop down.
   */
  public function updateDisplayCallback(array $form, FormStateInterface $form_state) {
    // Form rebuilt with required number of members before callback.
    // Return new form.
    return $form;
  }

  /**
   * Populate table with results of search form.
   */
  public function search(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }

  /**
   * Register new members for group.
   */
  public function addMembers(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    // Redirect to payment form.
    $form_state->setRedirect('conreg_portal_register',
      ['eid' => $eid]
    );
  }

  /**
   * Get the Member ID of the currently logged in user.
   */
  private function getUserLeadMid(int $eid) {
    $user_email = $this->currentUser()->getEmail();
    if ($member = $this->memberStorage->load(['eid' => $eid, 'email' => $user_email])) {
      return $member['mid'];
    }
  }

  /**
   * If any member upgrades selected, save them so they can be charged.
   */
  public function saveUpgrades(int $eid, ?array $form_values, ?float &$upgrade_price, Payment &$payment): int {
    $mgr = new UpgradeManager($this->upgradeStorage, $this->memberStorage, $this->time, $eid);

    // Get lead MID from .
    $lead_mid = $this->getUserLeadMid($eid);

    $upgrades = $form_values["table"];
    if (isset($upgrades) && is_array($upgrades)) {
      foreach ($upgrades as $mid => $memberRow) {
        $upgrade = new Upgrade($this->upgradeStorage, $this->memberStorage, $this->time, $eid, $mid, $memberRow["member_type"], $lead_mid);
        // Only save upgrade if price is not null.
        if (isset($upgrade->upgradePrice)) {
          $mgr->Add($upgrade);
          $member = $this->memberStorage->load(['mid' => $mid]);
          $payment->add(new PaymentLine(
            $mid,
            'upgrade',
            $this->t("Upgrade for @first_name @last_name", [
              '@first_name' => $member['first_name'],
              '@last_name' => $member['last_name'],
            ]),
            $upgrade->upgradePrice));
        }
      }
    }
    $upgrade_price = $mgr->getTotalPrice();
    $lead_mid = $mgr->saveUpgrades();
    return $lead_mid;
  }

  /**
   * Callback for "Pay by Card" button.
   *
   * Sets up members to be paid and transfers to Credit Card form.
   */
  public function payCard(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $form_values = $form_state->getValues();

    // Create a payment.
    $payment = new Payment();

    // Save any member upgrades.
    $upgrade_price = 0;
    $lead_mid = self::saveUpgrades($eid, $form_values, $upgrade_price, $payment);

    $payment_amount = 0;
    // Loop through selected members to get lead and total price.
    foreach ($form_values['unpaid'] ?? [] as $mid => $member) {
      if (isset($member['is_selected']) && $member['is_selected']) {
        if ($member = $this->memberStorage->load(['mid' => $mid])) {
          // Make first member lead member.
          if (empty($lead_mid)) {
            $lead_mid = $mid;
          }
          $payment_amount += $member['member_price'];
        }
      }
    }

    // Get the user email address.
    $email = $this->currentUser()->getEmail();

    // Load all members for email address (paid and unpaid)...
    $entries = $this->memberStorage->adminMemberPortalListLoad($eid, $email);

    foreach ($entries as $entry) {
      $mid = $entry['mid'];
      // Sanitize each entry.
      $is_paid = $entry['is_paid'];

      // If member unpaid, add to payment.
      if (!$is_paid) {
        $payment->add(new PaymentLine(
          $mid,
          'member',
          $this->t("Member registration for @first_name @last_name", [
            '@first_name' => $entry['first_name'],
            '@last_name' => $entry['last_name'],
          ]),
          $entry['member_price'],
        ));
        $payment_amount += $entry['member_price'];
      }

      // Loop through unpaid upgrades for member, and add those to payment.
      foreach ($this->addonStorage->loadAll(['mid' => $mid, 'is_paid' => 0]) as $addon) {
        $payment->add(new PaymentLine(
          $mid,
          'addon',
          $this->t("Add-on @add_on for @first_name @last_name", [
            '@add_on' => $addon['addon_name'],
            '@first_name' => $entry['first_name'],
            '@last_name' => $entry['last_name'],
          ]),
          $addon['addon_amount']
        ));
        // Update addon to set the payment ID.
        $this->addonStorage->update(['addonid' => $addon['addonid'], 'payid' => $payment->getId()]);
        $payment_amount += $addon['addon_amount'];
      }
    }

    // Assuming there are members/upgrades to pay for, redirect to payment form.
    if ($payment_amount > 0 || $upgrade_price > 0) {
      $payment->save();
      $form_state->setRedirect('conreg_portal_checkout',
        ['payid' => $payment->payId, 'key' => $payment->randomKey]
      );
    }
    else {
      $this->messenger()->addMessage($this->t('Nothing to pay for.'), 'warning');
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
  }

}
