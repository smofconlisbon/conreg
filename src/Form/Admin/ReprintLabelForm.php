<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\Form\ConfirmModalFormBase;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Service\PrintJobManager;
use Drupal\conreg\Trait\AssertMemberEventTrait;
use Drupal\conreg\Trait\MemberDisplayNameTrait;
use Drupal\conreg\Trait\PrinterSessionTrait;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Modal-dialog "Reprint label" action, for reprinting lost badges.
 *
 * Gated on its own "reprint convention member badge label" permission,
 * not "undo convention member check-in" (see conreg.routing.yml and
 * CheckInMembers.php) - both are supervisor-only actions on the same
 * dropbutton for an already checked-in member, but different-risk ones:
 * reprinting only consumes label stock, while undoing a check-in
 * mutates registration/payment state, so a site may want to grant one
 * without the other.
 *
 * The preview reuses PrintJobManager::renderPreviewImageForMember() -
 * the same rendering path CheckInLabelPreviewController's "Preview
 * label" option and CheckInMembers::buildConfirmForm() use - rather
 * than LabelPreviewController's preview()/testPrint() pair, which exist
 * for the Label Printing Settings page: that controller renders from
 * arbitrary posted field values with no member tie-in, and its
 * testPrint() queues an `is_test` job with no `mid`, gated on
 * "configure convention registration" rather than a check-in permission.
 * Confirming here calls PrintJobManager::createJobWithRenderedImage()
 * with that same rendered image, rather than rendering it again -
 * a reprint always reflects the member's current data through one
 * rendering path, and only ever renders it once per request.
 */
class ReprintLabelForm extends ConfirmModalFormBase {

  use AutowireTrait, PrinterSessionTrait, AssertMemberEventTrait, MemberDisplayNameTrait;

  /**
   * Construct the form.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\conreg\Service\PrintJobManager $printJobManager
   *   The print job manager.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected PrintJobManager $printJobManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_checkin_reprint_label';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = NULL, $mid = NULL) {
    $member = $this->memberStorage->load(['mid' => $mid]);
    if (!$member) {
      $form['message'] = ['#markup' => $this->t('Member not found.')];
      return $form;
    }
    $this->assertMemberBelongsToEvent($member, (int) $eid);

    $form_state->set('eid', $eid);
    $form_state->set('mid', $mid);
    // Carried in the ?search= query parameter on the link that opened
    // this modal (see CheckInMembers.php) - reused by getRedirectUrl()
    // so confirming doesn't land back on an empty search box, matching
    // UndoCheckInForm.
    $form_state->set('search', $this->getRequest()->query->get('search', ''));

    // The dropbutton link is already hidden when this is off (see
    // CheckInMembers.php), but that's only a UI nicety - this route is
    // reachable directly, so the actual gate has to live here too.
    $labelPrintingEnabled = $this->config('conreg.settings.' . $eid)->get('checkin.label_printing_enabled') ?? FALSE;
    if (!$labelPrintingEnabled) {
      $form['message'] = ['#markup' => $this->t('Label printing is not enabled for this event.')];
      return $form;
    }

    $imageBase64 = $this->printJobManager->renderPreviewImageForMember($member);
    if ($imageBase64 === NULL) {
      $form['message'] = ['#markup' => $this->t('No label preview is available for this member.')];
      return $form;
    }
    // Stashed for submitForm() to reuse via createJobWithRenderedImage()
    // - buildForm() always runs before submitForm() in the same
    // request, so a confirmed submission never re-renders this member's
    // label a second time just to queue the job.
    $form_state->set('imageBase64', $imageBase64);

    // Pulls in conreg.css for .conreg-label-preview-image (the
    // ConfirmModalFormBase::buildConfirmForm() call below already
    // covers the modal's own error-re-render wrapper class).
    $form['#attached']['library'][] = 'conreg/conreg_form';

    $form['preview'] = [
      '#type' => 'html_tag',
      '#tag' => 'img',
      '#attributes' => [
        'src' => 'data:image/png;base64,' . $imageBase64,
        'alt' => $this->t('Label preview'),
        'class' => ['conreg-label-preview-image'],
      ],
    ];

    $printers = $this->printJobManager->getPrintersForEvent((int) $eid);
    $printerOptions = [];
    foreach ($printers as $printer) {
      $printerOptions[$printer->get('machine_name')->value] = $printer->label();
    }

    if (!$printerOptions) {
      $form['no_printers'] = [
        '#markup' => $this->t('No printers are configured for this event, so the label cannot be reprinted.'),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->getCancelText(),
        // Points at the inherited no-op cancelSubmit(), not [] - see its
        // docblock on ConfirmModalFormBase for why that matters.
        '#submit' => ['::cancelSubmit'],
        '#limit_validation_errors' => [],
        '#ajax' => ['callback' => '::ajaxCancel'],
      ];
      return $form;
    }

    $rememberedPrinter = $this->getRememberedPrinter((int) $eid);

    $form['printer'] = [
      '#type' => 'select',
      '#title' => $this->t('Printer'),
      '#options' => $printerOptions,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => isset($printerOptions[$rememberedPrinter]) ? $rememberedPrinter : NULL,
      '#required' => TRUE,
    ];

    $question = $this->t('Reprint label for %name?', ['%name' => $this->memberDisplayName($member)]);

    return $this->buildConfirmForm($form, $question);
  }

  /**
   * {@inheritdoc}
   */
  protected function getConfirmText(): TranslatableMarkup|string {
    return $this->t('Print');
  }

  /**
   * {@inheritdoc}
   */
  protected function getCancelText(): TranslatableMarkup|string {
    return $this->t('Cancel');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mid = (int) $form_state->get('mid');
    $eid = (int) $form_state->get('eid');
    $printerMachineName = $form_state->getValue('printer');
    // Rendered by buildForm() earlier in this same request - see its
    // docblock comment there.
    $imageBase64 = (string) $form_state->get('imageBase64');

    $this->rememberPrinter($eid, $printerMachineName);

    try {
      $job = $this->printJobManager->createJobWithRenderedImage($mid, $printerMachineName, $imageBase64);
      $this->messenger()->addMessage($this->t('Reprint job for %badge_name queued on %printer.', [
        '%badge_name' => $job->get('member_name')->value,
        '%printer' => $form['printer']['#options'][$printerMachineName] ?? $printerMachineName,
      ]));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($this->t('Could not queue a reprint: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl(FormStateInterface $form_state): Url {
    $search = $form_state->get('search');
    $options = $search === '' ? [] : ['query' => ['search' => $search]];
    return Url::fromRoute('conreg_admin_checkin', ['eid' => $form_state->get('eid')], $options);
  }

}
