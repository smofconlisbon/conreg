<?php

declare(strict_types=1);

namespace Drupal\conreg\Controller;

use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Service\PrintJobManager;
use Drupal\conreg\Trait\AssertMemberEventTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Renders the "Preview label" modal dialog content from Member Check-In.
 *
 * A plain controller, not a form - the dialog has nothing to submit,
 * just an image and a Close button, closed client-side via the
 * `dialog-cancel` class (see core/misc/dialog/dialog.ajax.js) with no
 * AJAX round trip, so there is no need for CheckInBadgeNameForm's
 * #ajax/AjaxResponse machinery here.
 */
class CheckInLabelPreviewController extends ControllerBase {

  use AssertMemberEventTrait;

  public function __construct(
    protected MemberStorage $memberStorage,
    protected PrintJobManager $printJobManager,
  ) {}

  /**
   * Builds the modal's content.
   */
  public function build($eid, $mid) {
    // Loaded separately from renderPreviewImage()'s own internal load,
    // purely to check the member actually belongs to $eid before doing
    // anything else - see AssertMemberEventTrait. A missing member falls
    // through unchanged to the existing "No label preview" message
    // below (renderPreviewImage() already returns NULL for that case).
    $member = $this->memberStorage->load(['mid' => $mid]);
    if ($member) {
      $this->assertMemberBelongsToEvent($member, (int) $eid);
    }

    $imageBase64 = $this->printJobManager->renderPreviewImage((int) $mid);

    $build = [];

    if ($imageBase64 === NULL) {
      $build['message'] = [
        '#markup' => $this->t('No label preview is available for this member.'),
      ];
    }
    else {
      $build['image'] = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'src' => 'data:image/png;base64,' . $imageBase64,
          'alt' => $this->t('Label preview'),
          'class' => ['conreg-label-preview-image'],
        ],
      ];
    }

    $build['actions'] = ['#type' => 'actions'];
    $build['actions']['close'] = [
      '#type' => 'link',
      '#title' => $this->t('Close'),
      '#url' => Url::fromRoute('conreg_admin_checkin', ['eid' => $eid]),
      '#attributes' => ['class' => ['button', 'dialog-cancel']],
    ];

    // Pulls in conreg.css for .conreg-label-preview-image - this is a
    // separate route/controller from CheckInMembers, so nothing already
    // on the check-in page carries over automatically.
    $build['#attached']['library'][] = 'conreg/conreg_form';

    return $build;
  }

}
