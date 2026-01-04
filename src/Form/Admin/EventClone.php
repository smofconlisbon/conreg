<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\EventStorage;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Clone a ConReg event.
 */
class EventClone extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_event_clone';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    $form['event_name'] = [
      '#type' => 'textfield',
      '#title' => 'Name of new event',
      '#maxlength' => 256,
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clone'),
    ];

    $form['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#limit_validation_errors' => [],
      '#submit' => [[$this, 'submitFormCancel']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $oldEid = $form_state->get('eid');
    $vals = $form_state->getValues();
    // Create new event in events table.
    $event = [
      'event_name' => $vals['event_name'],
      'is_open' => 1,
    ];
    $newEid = EventStorage::insert($event);
    // Load source event configuration, then save as new event's configuration.
    $oldConfig = $this->config('conreg.settings.' . $oldEid);
    $newConfig = $this->configFactory()->getEditable('conreg.settings.' . $newEid);
    foreach ($oldConfig->get() as $key => $val) {
      $newConfig->set($key, $val);
    }
    $newConfig->save();
    // Show message confirming event creation.
    $this->messenger()->addMessage($this->t(
      'New event created for @event_name.',
      ['@event_name' => $vals['event_name']]
    ));
    // Redirect back to event list.
    $form_state->setRedirect('conreg_event_list');
  }

  /**
   * Cancel the clone - return to event list.
   */
  public function submitFormCancel(array &$form, FormStateInterface $form_state) {
    // Cancelling clone, so just redirect back to event list.
    $form_state->setRedirect('conreg_event_list');
  }

}
