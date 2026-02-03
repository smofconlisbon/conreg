<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\Service\EventStorage;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;

/**
 * Configure conreg settings for this site.
 */
class EventList extends ConfigFormBase {

  use AutowireTrait;

  /**
   * Constructs the EventAddOns form.
   *
   * @param \Drupal\conreg\Service\EventStorage $eventStorage
   *   The event storage service.
   */
  public function __construct(
    protected EventStorage $eventStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_event_list';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'conreg.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $events = $this->eventStorage->loadAll();

    $headers = [
      'event_name' => [
        'data' => $this->t('Event name'),
        'field' => 'event_name',
      ],
      'state' => ['data' => $this->t('State'), 'field' => 'is_open'],
      'link' => $this->t('Update'),
    ];

    $form['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ['id' => 'simple-conreg-admin-event-list'],
      '#empty' => $this->t('No entries available.'),
      '#sticky' => TRUE,
    ];

    foreach ($events as $event) {
      $eid = $event['eid'];
      // Sanitize each entry.
      $row = [];
      $row['event_name'] = [
        '#markup' => Html::escape($event['event_name']),
      ];
      $row['state'] = [
        '#markup' => $event['is_open'] ? $this->t('Open') : $this->t('Closed'),
      ];
      $row['link'] = [
        '#type' => 'dropbutton',
        '#links' => [
          'admin_button' => [
            'title' => $this->t('Admin'),
            'url' => Url::fromRoute('conreg_admin_members', ['eid' => $eid]),
          ],
          'config_button' => [
            'title' => $this->t('Configure'),
            'url' => Url::fromRoute('conreg_config', ['eid' => $eid]),
          ],
          'clone_button' => [
            'title' => $this->t('Clone'),
            'url' => Url::fromRoute('conreg_event_clone', ['eid' => $eid]),
          ],
        ],
      ];
      $form['table'][$eid] = $row;
    }

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
  }

}
