<?php

namespace Drupal\conreg\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Base class for a "Yes/No" confirmation opened as an AJAX modal dialog.
 *
 * A generic counterpart to CheckInBadgeNameForm's single-field modal: a
 * subclass supplies the question text and where to redirect afterwards
 * (getRedirectUrl()), and implements the actual action in submitForm()
 * (as any other form would) - this base class supplies the question
 * markup, the Yes/No buttons, and their AJAX wiring.
 *
 * A full-page redirect (rather than a targeted DOM update, as
 * CheckInBadgeNameForm does for its one text cell) is the reusable
 * default here, since a "yes/no, then do something" action often
 * changes more of the page than one cell can reflect - e.g. undoing a
 * check-in changes a whole check-in table row's shape (a checkbox
 * reappears, the row gains a click-to-select class, etc.), not just
 * its text. A subclass whose confirmed action is narrow enough for a
 * targeted update can override ajaxConfirm() instead.
 *
 * @see https://www.drupal.org/docs/develop/drupal-apis/ajax-api/ajax-dialog-boxes
 */
abstract class ConfirmModalFormBase extends FormBase {

  /**
   * Where the browser is sent after confirming. See class docblock.
   */
  abstract protected function getRedirectUrl(FormStateInterface $form_state): Url;

  /**
   * Label for the confirming button.
   */
  protected function getConfirmText(): TranslatableMarkup|string {
    return $this->t('Yes');
  }

  /**
   * Label for the declining button.
   */
  protected function getCancelText(): TranslatableMarkup|string {
    return $this->t('No');
  }

  /**
   * Assembles the question markup and Yes/No actions.
   *
   * Call from a subclass's buildForm(), after loading/validating whatever
   * the question text needs (see UndoCheckInForm).
   */
  protected function buildConfirmForm(array $form, TranslatableMarkup|string $question): array {
    // A stable selector ajaxConfirm() below can replace when re-showing
    // validation errors (see CheckInBadgeNameForm::ajaxSubmit() for the
    // same pattern) - every subclass gets this for free rather than
    // each defining and wiring up its own, as ReprintLabelForm used to.
    $form['#attributes']['class'][] = 'conreg-confirm-modal-form';

    $form['question'] = ['#markup' => '<p>' . $question . '</p>'];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->getConfirmText(),
      '#ajax' => ['callback' => '::ajaxConfirm'],
    ];

    $form['actions']['cancel'] = [
      // #type => submit, not button, matching CheckInBadgeNameForm's
      // Cancel button: Drupal's modal dialog JS only moves
      // .form-actions input[type=submit] into the footer (see
      // core/misc/dialog/dialog.ajax.js). #submit points at the no-op
      // cancelSubmit() below rather than [] - see its docblock for why
      // an empty array does NOT prevent submitForm() from running.
      '#type' => 'submit',
      '#value' => $this->getCancelText(),
      '#submit' => ['::cancelSubmit'],
      '#limit_validation_errors' => [],
      '#ajax' => ['callback' => '::ajaxCancel'],
    ];

    return $form;
  }

  /**
   * No-op submit handler for the cancel ("No") button.
   *
   * This can't just be an empty #submit array: when a clicked button's
   * own #submit resolves to an empty array, FormSubmitter::
   * executeSubmitHandlers() treats that as "no override" and falls back
   * to the form's top-level #submit - which FormBuilder::doBuildForm()
   * always populates with the form object's own submitForm() (see
   * FormBuilder.php's `$form['#submit'][] = '::submitForm';`). Without
   * this no-op handler in place of [], clicking Cancel would silently
   * run the confirm action's submitForm() anyway.
   */
  public function cancelSubmit(array &$form, FormStateInterface $form_state): void {}

  /**
   * AJAX callback for the confirm ("Yes") button.
   *
   * Runs after submitForm() (a subclass's normal submit handler, which
   * performs the actual action) as part of the same request - but only
   * if the submission actually validated. A subclass that adds its own
   * required field (e.g. ReprintLabelForm's "Printer") can still fail
   * validation here, and redirecting away in that case would silently
   * swallow whatever error was set: nothing would ever show it, since
   * the redirect abandons the rendered $form that carries it. Re-render
   * the modal in place instead, same as CheckInBadgeNameForm::
   * ajaxSubmit(). See the class docblock for why a full-page redirect is
   * the default when there's nothing to report.
   */
  public function ajaxConfirm(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      unset($form['#prefix'], $form['#suffix']);
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -10,
      ];
      $response->addCommand(new HtmlCommand('.conreg-confirm-modal-form', $form));
      return $response;
    }

    $response->addCommand(new RedirectCommand($this->getRedirectUrl($form_state)->setAbsolute()->toString()));
    return $response;
  }

  /**
   * AJAX callback for the decline ("No") button: closes without acting.
   */
  public function ajaxCancel(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

}
