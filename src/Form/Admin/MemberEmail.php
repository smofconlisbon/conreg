<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\Service\ConregEmailSender;
use Drupal\conreg\Service\MemberPresenter;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Trait\EasyEmailTypeOptionsTrait;
use Drupal\conreg\Trait\TokenTreeLinkTrait;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\easy_email\Service\EmailHandlerInterface;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class MemberEmail extends FormBase {

  use AutowireTrait;
  use EasyEmailTypeOptionsTrait;
  use TokenTreeLinkTrait;

  /**
   * Construct the form.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\conreg\Service\MemberPresenter $memberPresenter
   *   The member presenter service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temporary storage.
   * @param \Drupal\easy_email\Service\EmailHandlerInterface $emailHandler
   *   The Easy Email handler service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\conreg\Service\ConregEmailSender $emailSender
   *   Used here only for its populatePlainBody() helper.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected MemberPresenter $memberPresenter,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected EmailHandlerInterface $emailHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConregEmailSender $emailSender,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_member_email';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1, $mid = NULL) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    // Look up email address for member.
    $members = $this->memberStorage->loadAll(['eid' => $eid, 'mid' => $mid, 'is_deleted' => 0]);
    if (empty($members)) {
      $form['conreg_event'] = [
        '#markup' => $this->t('Member not found. Please confirm member valid.'),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
      return $form;
    }
    $emailAddress = $members[0]['email'];

    // Get any additional paid members registered by email address.
    $members = $this->memberStorage->loadAll([
      'eid' => $eid,
      'email' => $emailAddress,
      'is_paid' => 1,
      'is_deleted' => 0,
    ]);
    $mids = [$mid];
    foreach ($members as $member) {
      if ($member['mid'] != $mid && $member['lead_mid'] != $mid) {
        $mids[] = $member['mid'];
      }
    }

    // Present the primary member for the summary fields below - same data
    // ConregEmailer used to expose via $params.
    $group = $this->memberPresenter->loadGroup($eid, (int) $mid);
    $this->memberPresenter->present($eid, $group);
    $primary = $group[0] ?? [];

    // Build list of email templates.
    $templateOptions = $this->easyEmailTypeOptions();
    if (empty($templateOptions)) {
      $form['conreg_event'] = [
        '#markup' => $this->t('No email templates found. Please create one under <a href=":url">Email templates</a> first.', [':url' => '/admin/structure/email-templates']),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
      return $form;
    }

    $config = $this->config('conreg.settings.' . $eid);
    $from_email = $config->get('confirmation.from_email');
    $from_options = [$from_email => $from_email];
    $copy_to = $config->get('confirmation.copy_email_to');
    if (!empty($copy_to)) {
      $from_options[$copy_to] = $copy_to;
    }
    $user_email = $this->currentUser()->getEmail();
    $from_options[$user_email] = $user_email;

    $form_values = $form_state->getValues();
    $bundle = $form_values['template']['template_select']
      ?? $config->get('confirmation.easy_email_type')
      ?? array_key_first($templateOptions);
    if (!isset($templateOptions[$bundle])) {
      $bundle = array_key_first($templateOptions);
    }

    // Build a transient (unsaved) email from the selected template.
    $email = $this->emailHandler->createEmail([
      'type' => $bundle,
      'recipient_address' => [$emailAddress],
      'field_conreg_eid' => $eid,
      'field_conreg_mid' => $mids,
    ]);

    // If the template hasn't changed since the last build, keep any
    // subject/body the admin has typed rather than resetting to the raw
    // template text.
    $previousBundle = $form_state->get('bundle');
    $form_state->set('bundle', $bundle);
    if ($previousBundle === $bundle) {
      if (isset($form_values['email']['message']['subject'])) {
        $email->setSubject($form_values['email']['message']['subject']);
      }
      if (isset($form_values['email']['message']['body']['value'])) {
        $email->setHtmlBody(
          $form_values['email']['message']['body']['value'],
          $form_values['email']['message']['body']['format'],
        );
      }
    }
    else {
      // Template changed (or this is the first build): force the
      // rebuilt subject/body fields to actually show this template's own
      // raw text and format. Form API prefers previously-submitted user
      // input over #default_value on an AJAX rebuild, so without this,
      // switching templates would silently keep displaying (and, on the
      // next edit, keep sending) the *previous* template's text/format.
      $rawBody = $email->getHtmlBody();
      $userInput = $form_state->getUserInput() ?? [];
      NestedArray::setValue($userInput, ['email', 'message', 'subject'], $email->getSubject());
      NestedArray::setValue($userInput, ['email', 'message', 'body', 'value'], $rawBody['value'] ?? '');
      NestedArray::setValue($userInput, ['email', 'message', 'body', 'format'], $rawBody['format'] ?? NULL);
      $form_state->setUserInput($userInput);
    }
    if (!empty($form_values['email']['message']['from_email'])) {
      $email->setFromAddress($form_values['email']['message']['from_email']);
    }
    $this->emailSender->populatePlainBody($email);

    // Capture the raw (unresolved) subject/body before preview() below
    // resolves tokens in place on $email - the editable fields must show
    // the literal [conreg:...] tokens, not their resolved values, so the
    // admin can see and edit the actual template.
    $rawSubject = $email->getSubject();
    $rawHtmlBody = $email->getHtmlBody();

    // preview() resolves tokens in place on $email via the real Easy Email
    // send pipeline (hook_mail); the read-only preview section below uses
    // that resolved text, but the editable fields above use the raw
    // values captured before this call.
    $preview = $this->emailHandler->preview($email);
    $form_state->set('mid', $mid);
    $form_state->set('mids', $mids);

    $form = [
      '#tree' => TRUE,
      '#prefix' => '<div id="transfer-form">',
      '#suffix' => '</div>',
      '#attached' => [
        'library' => ['conreg/conreg_form', 'conreg/conreg_member_email_preview'],
      ],
    ];

    $form['member'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Member details'),
    ];

    $memberFields = [
      'is_approved' => $this->t('Approved: @value', ['@value' => $primary['is_approved'] ?? '']),
      'member_no' => $this->t('Member number: @value', ['@value' => $primary['member_no'] ?? '']),
      'email' => $this->t('Email: @value', ['@value' => $primary['email'] ?? '']),
      'first_name' => $this->t('First Name: @value', ['@value' => $primary['first_name'] ?? '']),
      'last_name' => $this->t('Last Name: @value', ['@value' => $primary['last_name'] ?? '']),
      'badge_name' => $this->t('Badge Name: @value', ['@value' => $primary['badge_name'] ?? '']),
      'is_paid' => $this->t('Paid: @value', ['@value' => $primary['is_paid'] ?? '']),
      'payment_method' => $this->t('Payment method: @value', ['@value' => $primary['payment_method'] ?? '']),
      'member_price' => $this->t('Price: @value', ['@value' => $primary['member_price'] ?? '']),
      'payment_id' => $this->t('Payment reference: @value', ['@value' => $primary['payment_id'] ?? '']),
      'comment' => $this->t('Comment: @value', ['@value' => $primary['comment'] ?? '']),
    ];
    foreach ($memberFields as $key => $markup) {
      $form['member'][$key] = [
        '#markup' => $markup,
        '#prefix' => '<div class="field">',
        '#suffix' => '</div>',
      ];
    }

    // Fields for selecting template.
    $form['template'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Template'),
      '#prefix' => '<div id="template">',
      '#suffix' => '</div>',
    ];

    $form['template']['template_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Select template to use (overwrites message)'),
      '#description' => $this->easyEmailTypeManageLink(),
      '#options' => $templateOptions,
      '#default_value' => $bundle,
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

    $form['email']['message'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email message'),
      '#prefix' => '<div id="message">',
      '#suffix' => '</div>',
      '#attributes' => ['class' => ['conreg-member-email-message']],
    ];

    $form['email']['message']['from_email'] = [
      '#type' => 'select',
      '#title' => $this->t('Send from email address'),
      '#options' => $from_options,
      '#default_value' => $email->getFromAddress() ?: $from_email,
      '#ajax' => [
        'wrapper' => 'email',
        'callback' => [$this, 'updateEmailPreview'],
        'event' => 'change',
      ],
    ];

    $form['email']['message']['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message subject'),
      '#default_value' => $rawSubject,
      '#ajax' => [
        'wrapper' => 'preview',
        'callback' => [$this, 'updateEmailPreview'],
        'event' => 'change',
      ],
    ];

    $form['email']['message']['body'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Message body'),
      '#description' => $this->t('Text for the email body. Supports tokens — use the browser below to see what is available.'),
      '#default_value' => $rawHtmlBody['value'] ?? '',
      '#format' => $rawHtmlBody['format'] ?? NULL,
      '#ajax' => [
        'wrapper' => 'preview',
        'callback' => [$this, 'updateEmailPreview'],
        'event' => 'change',
      ],
    ];

    $form['email']['message']['body_token_tree'] = $this->tokenTreeLink();

    $form['email']['preview'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Preview'),
      '#prefix' => '<div id="preview">',
      '#suffix' => '</div>',
    ];

    $form['email']['preview']['from'] = [
      '#markup' => $this->t('From: @from_email', ['@from_email' => $preview['from'] ?? '']),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['email']['preview']['to'] = [
      '#markup' => $this->t('To: @to_email', ['@to_email' => $emailAddress]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['email']['preview']['subject'] = [
      '#markup' => $this->t('Subject: @subject', ['@subject' => $preview['subject'] ?? '']),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div><hr />',
    ];

    $previewBody = $preview['body'] ?? '';
    $form['email']['preview']['body'] = [
      '#markup' => is_array($previewBody) ? implode("\n", $previewBody) : $previewBody,
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send email'),
    ];

    $form['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => [[$this, 'submitCancel']],
    ];

    return $form;
  }

  /**
   * Callback function for Template drop-down.
   *
   * Loads message fields associated with the selected template.
   */
  public function updateEmailTemplate(array $form, FormStateInterface $form_state) {
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
    $tempstore = $this->tempStoreFactory->get('conreg');
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
    $mids = $form_state->get('mids');
    $bundle = $form_state->get('bundle');

    $form_values = $form_state->getValues();

    $members = $this->memberStorage->loadAll(['eid' => $eid, 'mid' => $form_state->get('mid'), 'is_deleted' => 0]);
    $to = $members[0]['email'] ?? NULL;

    $email = $this->emailHandler->createEmail([
      'type' => $bundle,
      'recipient_address' => [$to],
      'field_conreg_eid' => $eid,
      'field_conreg_mid' => $mids,
    ]);
    $email->setSubject($form_values['email']['message']['subject']);
    $email->setHtmlBody(
      $form_values['email']['message']['body']['value'],
      $form_values['email']['message']['body']['format'],
    );
    if (!empty($form_values['email']['message']['from_email'])) {
      $email->setFromAddress($form_values['email']['message']['from_email']);
    }
    $this->emailSender->populatePlainBody($email);
    // Save the entity so this send appears in Easy Email's log.
    $this->emailHandler->sendEmail($email, [], FALSE, TRUE);

    // Get session state to return to correct page.
    $tempstore = $this->tempStoreFactory->get('conreg');
    $display = $tempstore->get('display');
    $page = $tempstore->get('page');
    // Redirect to member list.
    $form_state->setRedirect('conreg_admin_members', ['eid' => $eid, 'display' => $display, 'page' => $page]);
  }

}
