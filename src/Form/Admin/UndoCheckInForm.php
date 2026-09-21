<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\Form\ConfirmModalFormBase;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Trait\AssertMemberEventTrait;
use Drupal\conreg\Trait\MemberDisplayNameTrait;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Modal-dialog confirmation for returning a checked-in member to pending.
 */
class UndoCheckInForm extends ConfirmModalFormBase {

  use AutowireTrait, AssertMemberEventTrait, MemberDisplayNameTrait;

  public function __construct(
    protected MemberStorage $memberStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_checkin_undo';
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
    // Carried in the ?search= query parameter on the link that opened this
    // modal (see CheckInMembers.php) - reused by getRedirectUrl() so
    // confirming doesn't land back on an empty search box.
    $form_state->set('search', $this->getRequest()->query->get('search', ''));

    $question = $this->t('Are you sure you want to return %name to not checked in?', ['%name' => $this->memberDisplayName($member)]);

    return $this->buildConfirmForm($form, $question);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mid = $form_state->get('mid');
    $this->memberStorage->update(['mid' => $mid, 'is_checked_in' => 0]);
    Cache::invalidateTags(['conreg-member-list']);
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
