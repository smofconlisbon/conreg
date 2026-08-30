<?php

namespace Drupal\conreg\Form;

use Drupal\conreg\ConregConfig;
use Drupal\conreg\Service\ConregEmailSender;
use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\Service\MemberStorage;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class CheckMember extends FormBase {

  use AutowireTrait;

  /**
   * Constructs a new EmailExampleGetFormPage.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\conreg\Service\ConregEmailSender $emailSender
   *   Builds and sends conreg emails via Easy Email.
   * @param \Drupal\conreg\Service\EventStorage $eventStorage
   *   The event storage service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected ConregEmailSender $emailSender,
    protected EventStorage $eventStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_check_member';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    // Fetch event name from Event table.
    if (count($this->eventStorage->load(['eid' => $eid])) < 3) {
      // Event not in database. Display error.
      $form['conreg_event'] = [
        '#markup' => $this->t('Event not found. Please contact site admin.'),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
      return $form;
    }

    // Get config for event and fieldset.
    $config = ConregConfig::getConfig($eid);
    if (empty($config->get('payments.system'))) {
      // Event not configured. Display error.
      $form['conreg_event'] = [
        '#markup' => $this->t('Event not found. Please contact site admin.'),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
      return $form;
    }

    $form = [
      '#tree' => TRUE,
      '#cache' => ['max-age' => 0],
      '#title' => $config->get('member_check.title'),
      '#prefix' => '<div id="regform">',
      '#suffix' => '</div>',
      '#attached' => [
        'library' => ['conreg/conreg_form'],
      ],
    ];

    $form['intro'] = [
      '#markup' => $config->get('member_check.intro'),
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#description' => $this->t('Please provide the email address that was used to register.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check member details'),
      '#attributes' => ["onclick" => "jQuery(this).attr('disabled', TRUE); jQuery(this).parents('form').submit();"],
    ];
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

    $eid = $form_state->get('eid');
    $config = ConregConfig::getConfig($eid);
    $form_values = $form_state->getValues();

    // Get all members registered by email address.
    $members = $this->memberStorage->loadAll([
      'eid' => $eid,
      'email' => $form_values['email'],
      'is_paid' => 1,
      'is_deleted' => 0,
    ]);

    if (count($members)) {
      $mids = array_map(fn (array $member) => (int) $member['mid'], $members);
      $bundle = $config->get('member_check.confirm_easy_email_type');
      $langcode = $members[0]['language'] ?? NULL;
      $info = 'Member details found and sent to @email.';
    }
    else {
      $mids = [];
      $bundle = $config->get('member_check.unknown_easy_email_type');
      $langcode = NULL;
      $info = 'Member details not found for @email.';
    }

    $this->emailSender->send($bundle, $form_values['email'], (int) $eid, $mids, $langcode);

    // Log an event to show a member check occurred.
    $this->logger('conreg')->info($info, ['@email' => $form_values['email']]);

    // Display a status message to let user know an email has been sent.
    $this->messenger()->addMessage($this->t("An email has been sent to @email. If you don't find it, please check your spam folder.", ['@email' => $form_values['email']]));
  }

}
