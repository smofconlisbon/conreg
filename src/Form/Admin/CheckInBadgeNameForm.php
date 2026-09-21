<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\Component\Utility\Html;
use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Trait\AssertMemberEventTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Modal dialog form for editing a member's badge name from Member Check-In.
 *
 * @see https://www.drupal.org/docs/develop/drupal-apis/ajax-api/ajax-dialog-boxes
 */
class CheckInBadgeNameForm extends FormBase {

  use AutowireTrait, AssertMemberEventTrait;

  /**
   * Construct the form.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\conreg\Service\ConregOptions $conregOptions
   *   The ConReg options service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected ConregOptions $conregOptions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_checkin_badge_name';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = NULL, $mid = NULL) {
    $member = $this->memberStorage->load(['mid' => $mid]);
    if (!$member) {
      $form['message'] = [
        '#markup' => $this->t('Member not found.'),
      ];
      return $form;
    }
    $this->assertMemberBelongsToEvent($member, (int) $eid);

    $form_state->set('eid', $eid);
    $form_state->set('mid', $mid);

    $memberType = trim($member['member_type'] ?? '');
    $types = $this->conregOptions->memberTypes($eid);
    $memberClasses = $this->conregOptions->memberClasses($eid);
    $curMemberClassRef = (!empty($memberType) && isset($types->types[$memberType]))
      ? $types->types[$memberType]->memberClass
      : array_key_first($memberClasses->classes);
    $curMemberClass = $memberClasses->classes[$curMemberClassRef] ?? NULL;
    $badgeNameMaxLength = $curMemberClass->max_length->badge_name ?? NULL;

    $form['#attributes']['class'][] = 'conreg-badge-name-modal-form';

    $form['badge_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Badge name'),
      '#default_value' => $member['badge_name'],
      '#maxlength' => (empty($badgeNameMaxLength) ? 128 : $badgeNameMaxLength),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
      ],
    ];

    $form['actions']['cancel'] = [
      // #type => submit, not button: Drupal's modal dialog JS only moves
      // .form-actions input[type=submit] (and link-styled buttons) into
      // the dialog's footer (see core/misc/dialog/dialog.ajax.js) - a
      // #type => button renders type="button" and is left behind in the
      // dialog body, next to the Update button in the footer. #submit
      // points at the no-op cancelSubmit() below rather than [] - see
      // its docblock for why an empty array does NOT prevent
      // submitForm() (and so a real save) from running anyway.
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::cancelSubmit'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::ajaxCancel',
      ],
    ];

    return $form;
  }

  /**
   * No-op submit handler for the cancel button.
   *
   * This can't just be an empty #submit array: when a clicked button's
   * own #submit resolves to an empty array, FormSubmitter::
   * executeSubmitHandlers() treats that as "no override" and falls back
   * to the form's top-level #submit - which FormBuilder::doBuildForm()
   * always populates with the form object's own submitForm(). Without
   * this no-op handler in place of [], clicking Cancel would silently
   * save the badge name anyway.
   */
  public function cancelSubmit(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mid = $form_state->get('mid');
    $badge_name = trim((string) $form_state->getValue('badge_name'));

    $this->memberStorage->update(['mid' => $mid, 'badge_name' => $badge_name]);

    $eid = $form_state->get('eid');
    $form_state->setRedirect('conreg_admin_checkin', ['eid' => $eid]);
  }

  /**
   * AJAX submit callback: closes the dialog and updates the check-in row.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    $ajax_response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      unset($form['#prefix'], $form['#suffix']);
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -10,
      ];
      $ajax_response->addCommand(new HtmlCommand('.conreg-badge-name-modal-form', $form));
      return $ajax_response;
    }

    $mid = $form_state->get('mid');
    $badge_name = trim((string) $form_state->getValue('badge_name'));

    $ajax_response->addCommand(new CloseModalDialogCommand());
    $ajax_response->addCommand(new HtmlCommand('#conreg-badge-name-cell-' . $mid, Html::escape($badge_name)));

    return $ajax_response;
  }

  /**
   * AJAX callback for the Cancel button: closes the dialog without saving.
   */
  public function ajaxCancel(array &$form, FormStateInterface $form_state) {
    $ajax_response = new AjaxResponse();
    $ajax_response->addCommand(new CloseModalDialogCommand());
    return $ajax_response;
  }

}
