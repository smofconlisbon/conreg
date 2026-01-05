<?php

namespace Drupal\conreg;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class SimpleConregAdminMemberEmail extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'conreg_admin_member_email';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1, $mid = NULL) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    // Look up email address for member.
    $members = ConregStorage::loadAll(['eid' => $eid, 'mid' => $mid, 'is_deleted' => 0]);
    $email = $members[0]['email'];

    // Get any additional paid members registered by email address.
    $members = ConregStorage::loadAll(['eid' => $eid, 'email' => $email, 'is_paid' => 1, 'is_deleted' => 0]);
    $mids = [$mid];
    if (count($members)) {
      foreach ($members as $member) {
        if ($member['mid'] != $mid && $member['lead_mid'] != $mid) {
          $mids[] = $member['mid'];
        }
      }
    }

    // Set up params array with eid and mid.
    $params = ['eid' => $eid, 'mid' => $mids];

    // Get any existing form values for use in AJAX validation.
    $form_values = $form_state->getValues();
    $config = $this->config('conreg.settings.' . $eid);

    // Set up array of from email address options.
    $from_email = $config->get('confirmation.from_email');
    $from_options = [$from_email => $from_email];
    $copy_to = $config->get('confirmation.copy_email_to');
    if (!empty($copy_to)) {
      $from_options[$copy_to] = $copy_to;
    }
    $user_email = \Drupal::currentUser()->getEmail();
    $from_options[$user_email] = $user_email;
    // Default email to the event from email, unless different address previously selected.
    $from_default = $from_email;

    // Build list of templates.
    $template_config = $this->config('conreg.email_templates');
    $options = [];
    $templates = [];
    if (empty($count = $template_config->get('count'))) {
      $count = 0;
    }
    for ($template = 1; $template <= $count; $template++) {
      $subject = $template_config->get('template' . $template . 'subject');
      $body = $template_config->get('template' . $template . 'body');
      $format = $template_config->get('template' . $template . 'format');
      $options[$template] = $subject;
      $templates[$template] = ['subject' => $subject, 'body' => $body, 'format' => $format];
    }
    // Store templates to form state.
    $form_state->set('templates', $templates);

    // Check if default template selected.
    if (!isset($form_values['template']) || NULL == $default_template = $form_values['template']['template_select']) {
      $default_template = 1;
    }

    $previous_template = $form_state->get('default_template');
    $form_state->set('default_template', $default_template);

    // If form submitted, use submitted values, otherwise use defaults.
    if (empty($params['from'] = $form_values['email']['message']['from_email'])) {
      $params['from'] = $from_default;
    }

    if ($previous_template != $default_template || !isset($form_values['email']) || empty($params['subject'] = $form_values['email']['message']['subject' . $default_template])) {
      $params['subject'] = $templates[$default_template]['subject'];
    }

    if ($previous_template != $default_template || !isset($form_values['email']) || empty($params['body'] = $form_values['email']['message']['body' . $default_template]['value'])) {
      $params['body'] = $templates[$default_template]['body'];
    }

    if ($previous_template != $default_template || !isset($form_values['email']) || empty($params['body_format'] = $form_values['email']['message']['body' . $default_template]['format'])) {
      $params['body_format'] = $templates[$default_template]['format'];
    }

    // If tokens stored in form state, store in params to save looking up again.
    if (NULL != $tokens = $form_state->get('tokens')) {
      $params['tokens'] = $tokens;
    }

    $message = [];
    ConregEmailer::createEmail($message, $params);
    $params = $message['params'];
    $form_state->set('params', $params);

    // Check member exists.
    if (!isset($params['mid'])) {
      // Event not in database. Display error.
      $form['conreg_event'] = [
        '#markup' => $this->t('Member not found. Please confirm member valid.'),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
      return $form;
    }

    $form = [
      '#tree' => TRUE,
      '#prefix' => '<div id="transfer-form">',
      '#suffix' => '</div>',
      '#attached' => [
        'library' => ['conreg/conreg_form'],
      ],
    ];

    $form['member'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Member details'),
    ];

    $form['member']['is_approved'] = [
      '#markup' => $this->t('Approved: @approved', ['@approved' => $params['is_approved']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['member_no'] = [
      '#markup' => $this->t('Member number: @member_no', ['@member_no' => $params['member_no']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['email'] = [
      '#markup' => $this->t('Email: @email', ['@email' => $params['email']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['first_name'] = [
      '#markup' => $this->t('First Name: @first_name', ['@first_name' => $params['first_name']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['last_name'] = [
      '#markup' => $this->t('Last Name: @last_name', ['@last_name' => $params['last_name']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['badge_name'] = [
      '#markup' => $this->t('Badge Name: @badge_name', ['@badge_name' => $params['badge_name']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['is_paid'] = [
      '#markup' => $this->t('Paid: @is_paid', ['@is_paid' => $params['is_paid']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['payment_method'] = [
      '#markup' => $this->t('Payment method: @payment_method', ['@payment_method' => $params['payment_method']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['member_price'] = [
      '#markup' => $this->t('Price: @member_price', ['@member_price' => $params['member_price']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['payment_id'] = [
      '#markup' => $this->t('Payment reference: @payment_id', ['@payment_id' => $params['payment_id']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['comment'] = [
      '#markup' => $this->t('Comment: @comment', ['@comment' => $params['comment']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    // Fields for selecting template.
    $form['template'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Template'),
      '#prefix' => '<div id="template">',
      '#suffix' => '</div>',
    ];

    // Template selection drop-down.
    $form['template']['template_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Select template to use (overwrites message)'),
      '#options' => $options,
      '#default_value' => 1,
      '#ajax' => [
        'wrapper' => 'email',
        'callback' => [$this, 'updateEmailTemplate'],
        'event' => 'change',
      ],
    ];

    // Container for message fields and preview.
    $form['email'] = [
      '#prefix' => '<div id="email">',
      '#suffix' => '</div>',
    ];

    // Fields for writing email message.
    $form['email']['message'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email message'),
      '#prefix' => '<div id="message">',
      '#suffix' => '</div>',
    ];

    $form['email']['message']['from_email'] = [
      '#type' => 'select',
      '#title' => $this->t('Send from email address'),
      '#options' => $from_options,
      '#default_value' => $from_default,
      '#ajax' => [
        'wrapper' => 'email',
        'callback' => [$this, 'updateEmailPreview'],
        'event' => 'change',
      ],
    ];

    if (empty($template = $form_values['template']['template_select'])) {
      $template = 1;
    }
    $form['email']['message']['subject' . $template] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message subject'),
      '#default_value' => $params['subject'],
      '#ajax' => [
        'wrapper' => 'preview',
        'callback' => [$this, 'updateEmailPreview'],
        'event' => 'change',
      ],
    ];

    $form['email']['message']['body' . $template] = [
      // '#type' => 'textarea',
      '#type' => 'text_format',
      '#title' => $this->t('Message body'),
      '#description' => $this->t('Text for the email body. you may use the following tokens: @tokens.', ['@tokens' => ConregTokens::tokenHelp()]),
      '#default_value' => $params['body'],
      // '#value' => $params['body'],
      '#format' => $params['format'],
      '#ajax' => [
        'wrapper' => 'preview',
        'callback' => [$this, 'updateEmailPreview'],
        'event' => 'change',
      ],
    ];

    // Fields for writing email message.
    $form['email']['preview'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Preview'),
      '#prefix' => '<div id="preview">',
      '#suffix' => '</div>',
    ];

    $form['email']['preview']['from'] = [
      '#markup' => $this->t('From: @from_email', ['@from_email' => $params['from']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['email']['preview']['to'] = [
      '#markup' => $this->t('To: @to_email', ['@to_email' => $params['to']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['email']['preview']['subject'] = [
      '#markup' => $this->t('Subject: @subject', ['@subject' => $message['subject']]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div><hr />',
    ];

    $form['email']['preview']['body'] = [
      '#markup' => $message['preview'],
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Send email'),
    ];

    $form['cancel'] = [
      '#type' => 'submit',
      '#value' => t('Cancel'),
      '#submit' => [[$this, 'submitCancel']],
    ];

    $form_state->set('mid', $mid);
    return $form;
  }

  /**
   * Callback function for Template drop down - load message fields with template.
   */
  public function updateEmailTemplate(array $form, FormStateInterface $form_state) {
    /*$form_values = $form_state->getValues();
    $templates = $form_state->get('templates');

    if (!empty($template = $form_values['template']['template_select'])) {
    $params = $form_state->get('params');
    $params['subject'] = $templates[$template]['subject'.$template];
    $params['body'] = $templates[$template]['body'.$template];
    $message = [];
    ConregEmailer::createEmail($message, $params);
    $params = $message['params'];
    $form_state->set('params', $params);

    $form['email']['preview']['subject']['#markup'] = $this->t('Subject: @subject', ['@subject' => $message['subject']]);
    $form['email']['preview']['body']['#markup'] = $message['preview'];
    }*/
    return $form['email'];
  }

  /**
   * Callback function for message fields - update preview.
   */
  public function updateEmailPreview(array $form, FormStateInterface $form_state) {
    return $form['email']['preview'];
  }

  /**
   * Submit handler for cancel button.
   */
  public function submitCancel(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    // Get session state to return to correct page.
    $tempstore = \Drupal::service('tempstore.private')->get('conreg');
    $display = $tempstore->get('display');
    $page = $tempstore->get('page');
    // Redirect to member list.
    $form_state->setRedirect('conreg_admin_members', ['eid' => $eid, 'display' => $display, 'page' => $page]);
  }

  /**
   * Submit handler for member email form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');

    $form_values = $form_state->getValues();
    $template = $form_values['template']['template_select'];

    $params = $form_state->get('params');
    $params['subject'] = $form_values['email']['message']['subject' . $template];
    $params['body'] = $form_values['email']['message']['body' . $template]['value'];
    $params['body_format'] = $form_values['email']['message']['body' . $template]['format'];
    $module = "conreg";
    $key = "template";
    $to = $params["to"];
    $language_code = \Drupal::languageManager()->getDefaultLanguage()->getId();
    // Get session state to return to correct page.
    $tempstore = \Drupal::service('tempstore.private')->get('conreg');
    $display = $tempstore->get('display');
    $page = $tempstore->get('page');
    // Send confirmation email to member.
    \Drupal::service('plugin.manager.mail')->mail($module, $key, $to, $language_code, $params);

    // Redirect to member list.
    $form_state->setRedirect('conreg_admin_members', ['eid' => $eid, 'display' => $display, 'page' => $page]);
  }

}
