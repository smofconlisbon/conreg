<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\ConregTokens;
use Drupal\conreg\ConregOptions;
use Drupal\conreg\FieldOptions;
use Drupal\conreg\Service\MemberStorage;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class BulkEmail extends FormBase {

  use AutowireTrait;

  /**
   * Construct the form.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_bulk_email';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    $config = $this->config('conreg.settings.' . $eid);

    $form = [
      '#tree' => TRUE,
      '#prefix' => '<div id="bulk-mail-form">',
      '#suffix' => '</div>',
      '#attached' => [
        'library' => ['conreg/conreg_bulk_email'],
      ],
    ];

    // Fields for writing email message.
    $form['bulk_email'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email message'),
      '#prefix' => '<div id="message">',
      '#suffix' => '</div>',
    ];

    $form['bulk_email']['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From email name'),
      '#description' => $this->t('Name that confirmation email is sent from.'),
      '#default_value' => $config->get('bulk_email.from_name'),
    ];

    $form['bulk_email']['from_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From email address'),
      '#description' => $this->t('Email address that confirmation email is sent from (if you check the above box, a copy will also be sent to this address).'),
      '#default_value' => $config->get('bulk_email.from_email'),
    ];

    $form['bulk_email']['template_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bulk email subject'),
      '#default_value' => $config->get('bulk_email.template_subject'),
    ];

    $form['bulk_email']['template_body'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Bulk email body'),
      '#description' => $this->t('Text for the email body. you may use the following tokens: @tokens.', ['@tokens' => ConregTokens::tokenHelp()]),
      '#default_value' => $config->get('bulk_email.template_body'),
      '#format' => $config->get('bulk_email.template_format'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Template'),
    ];

    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sending Options'),
    ];
    $memberTypes = ConregOptions::memberTypes($eid, $config);
    $form['options']['member_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Member types'),
      '#options' => $memberTypes->privateOptions,
    ];
    $badgeTypes = ConregOptions::badgeTypes($eid, $config);
    $form['options']['badge_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Badge types'),
      '#options' => $badgeTypes,
    ];

    $communicationMethods = ConregOptions::communicationMethod($eid, $config, FALSE);
    $form['options']['communication_methods'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Communication methods'),
      '#options' => $communicationMethods,
    ];

    $fieldOptions = FieldOptions::getFieldOptionsTitles($eid);
    $form['options']['field_options'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Options'),
      '#options' => $fieldOptions,
    ];
    $form['options']['member_range'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Member Number range to email'),
      '#description' => $this->t('Enter Member Nos to send emails to. Use commas (,) to separate ranges and hyphens (-) to separate range limits, e.g. "1,3,5-7".'),
    ];
    $form['options']['delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Delay (in milliseconds)'),
      '#default_value' => '1000',
    ];

    $form['do_prepare'] = [
      '#type' => 'button',
      '#value' => $this->t('Prepare'),
      '#ajax' => [
        'wrapper' => 'upload',
        'callback' => [$this, 'prepareBulkSend'],
        'event' => 'click',
      ],
    ];

    $form['do_sending'] = [
      '#type' => 'button',
      '#value' => $this->t('Send bulk emails'),
      '#attributes' => ['onclick' => 'return (false);'],
    ];

    $form['sending'] = [
      '#prefix' => '<div id="upload">',
      '#suffix' => '</div>',
    ];
    $form['sending']['ids'] = [
      '#id' => 'edit-sending-ids',
      '#type' => 'textarea',
      '#title' => $this->t('IDs to upload'),
    ];

    return $form;
  }

  /**
   * Submit handler for member email form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $vals = $form_state->getValues();
    $config = $this->configFactory()->getEditable('conreg.settings.' . $eid);
    $config->set('bulk_email.from_name', $vals['bulk_email']['from_name']);
    $config->set('bulk_email.from_email', $vals['bulk_email']['from_email']);
    $config->set('bulk_email.template_subject', $vals['bulk_email']['template_subject']);
    $config->set('bulk_email.template_body', $vals['bulk_email']['template_body']['value']);
    $config->set('bulk_email.template_format', $vals['bulk_email']['template_body']['format']);
    $config->save();
  }

  /**
   * Callback function preparing send.
   */
  public function prepareBulkSend(array $form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $vals = $form_state->getValues();
    // Get list of member types, and remove keys for any that aren't selected.
    $ids = [];
    $options = [];
    $options['member_types'] = array_filter($vals['options']['member_types'] ?? [], fn($item) => !empty($item));
    $options['badge_types'] = array_filter($vals['options']['badge_types'] ?? [], fn($item) => !empty($item));
    $options['communication_methods'] = array_filter($vals['options']['communication_methods'] ?? [], fn($item) => !empty($item));
    $options['field_options'] = array_filter($vals['options']['field_options'] ?? [], fn($item) => !empty($item));
    $options['member_range'] = ($vals['options']['member_range'] ?? 0);
    // For all members in range, if type in selection, add to list.
    foreach ($this->memberStorage->adminMemberBadges($eid, FALSE, $options) as $member) {
      $ids[] = $member['mid'];
    }
    $form['sending']['ids']['#value'] = implode(" ", $ids);
    return $form['sending'];
  }

}
