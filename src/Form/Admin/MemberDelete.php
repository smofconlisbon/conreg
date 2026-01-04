<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\ConregOptions;
use Drupal\conreg\Member;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class MemberDelete extends FormBase {

  use AutowireTrait;

  final public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_member_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1, $mid = NULL) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    if (isset($mid)) {
      $member = Member::loadMember($mid);
    }

    // Check member exists.
    if (!(is_object($member) && $member->eid == $eid && !$member->is_deleted)) {
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
      '#prefix' => '<div id="delete-form">',
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
      '#markup' => $this->t('Approved: @approved', ['@approved' => ($member->is_approved ? $this->t('Yes') : $this->t('No'))]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['member_no'] = [
      '#markup' => $this->t('Member number: @member_no', ['@member_no' => ($member->member_no ? $member->member_no : '')]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['first_name'] = [
      '#markup' => $this->t('First Name: @first_name', ['@first_name' => $member->first_name]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['last_name'] = [
      '#markup' => $this->t('Last Name: @last_name', ['@last_name' => $member->last_name]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['badge_name'] = [
      '#markup' => $this->t('Badge Name: @badge_name', ['@badge_name' => $member->badge_name]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['is_paid'] = [
      '#markup' => $this->t('Paid: @is_paid', ['@is_paid' => ($member->is_paid ? $this->t('Yes') : $this->t('No'))]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $methods = ConregOptions::paymentMethod();
    $member_method = ($methods[$member->payment_method] ?? '');
    $form['member']['payment_method'] = [
      '#markup' => $this->t('Payment method: @payment_method', ['@payment_method' => $member_method]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['member_price'] = [
      '#markup' => $this->t('Price: @member_price', ['@member_price' => $member->member_price]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['payment_id'] = [
      '#markup' => $this->t('Payment reference: @payment_id', ['@payment_id' => $member->payment_id]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['member']['comment'] = [
      '#markup' => $this->t('Comment: @comment', ['@comment' => $member->comment]),
      '#prefix' => '<div class="field">',
      '#suffix' => '</div>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete member'),
    ];

    $form['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => [[$this, 'submitCancel']],
    ];

    $form_state->set('mid', $mid);
    $form_state->set('member', $member);
    return $form;
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
   * Submit handler for member edit form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $member = $form_state->get('member');

    $member->deleteMember();

    // Get session state to return to correct page.
    $tempstore = $this->tempStoreFactory->get('conreg');
    $display = $tempstore->get('display');
    $page = $tempstore->get('page');
    // Redirect to member list.
    $form_state->setRedirect('conreg_admin_members', ['eid' => $eid, 'display' => $display, 'page' => $page]);
  }

}
