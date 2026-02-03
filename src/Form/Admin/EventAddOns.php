<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\ConregConfig;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure conreg settings for this site.
 */
class EventAddOns extends ConfigFormBase {

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
    return 'conreg_event_addons';
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
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    // Fetch event name from Event table.
    if (count($event = $this->eventStorage->load(['eid' => $eid])) < 3) {
      // Event not in database. Display error.
      $form['conreg_event'] = [
        '#markup' => $this->t('Event not found. Please contact site admin.'),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
      return parent::buildForm($form, $form_state);
    }

    // Get config for event.
    $config = ConregConfig::getConfig($eid);

    $form['admin'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-new_addon',
    ];

    $form['new_addon'] = [
      '#type' => 'details',
      '#title' => $this->t('New Add-On'),
      '#tree' => TRUE,
      '#group' => 'admin',
      '#weight' => -100,
    ];

    $form['new_addon']['addon_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Add-on name'),
    ];

    /*
     * Placeholder for options for each add-on.
     */
    $form['addons'] = [
      '#tree' => TRUE,
    ];

    /*
     * Loop through each add-on and add to form.
     */

    foreach ($config->get('add-ons') ?? [] as $addOnId => $addOnVals) {

      /*
       * Fields for add on choices and options.
       */
      $form['addons'][$addOnId] = [
        '#type' => 'details',
        '#title' => $addOnId,
        '#group' => 'admin',
        '#weight' => (!empty($addOnVals['weight']) ? $addOnVals['weight'] : 0),
      ];

      $form['addons'][$addOnId]['addon'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Add-on Options'),
        '#tree' => TRUE,
      ];

      $addon = ($addOnVals['addon'] ?? []);

      $form['addons'][$addOnId]['addon']['active'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Active (uncheck to hide option from form)'),
        '#default_value' => ($addon['active'] ?? ''),
      ];

      $form['addons'][$addOnId]['addon']['global'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Global add-on (uncheck for add-on per member)'),
        '#default_value' => ($addon['global'] ?? ''),
      ];

      $form['addons'][$addOnId]['addon']['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#default_value' => ($addon['label'] ?? ''),
      ];

      $form['addons'][$addOnId]['addon']['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => ($addon['description'] ?? ''),
      ];

      $form['addons'][$addOnId]['addon']['options'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Options'),
        '#description' => $this->t('Put each option on a line with name, description and price separated by | character (e.g. "tshirt|Include a T-shirt|10").'),
        '#default_value' => ($addon['options'] ?? ''),
      ];

      $form['addons'][$addOnId]['addon']['weight'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Weight'),
        '#default_value' => ($addon['weight'] ?? '0'),
      ];

      $info = ($addOnVals['info'] ?? []);

      $form['addons'][$addOnId]['info'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Add-on Information'),
        '#tree' => TRUE,
      ];

      $form['addons'][$addOnId]['info']['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#description' => $this->t('If you would like to capture optional information about the add-on, please provide label and description for the information field.'),
        '#default_value' => ($info['label'] ?? ''),
      ];

      $form['addons'][$addOnId]['info']['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => ($info['description'] ?? ''),
      ];

      $free = ($addOnVals['free'] ?? []);

      $form['addons'][$addOnId]['free'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Add-on Free Input Amount'),
        '#tree' => TRUE,
      ];

      $form['addons'][$addOnId]['free']['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#default_value' => ($free['label'] ?? ''),
      ];

      $form['addons'][$addOnId]['free']['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => ($free['description'] ?? ''),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $vals = $form_state->getValues();
    // If new add-on populated, create an empty array.
    if (str_contains($vals['new_addon']['addon_name'] ?: '', ' ')) {
      $form_state->setErrorByName('new_addon][addon_name', $this->t('Add-on name must not contain spaces'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');

    $vals = $form_state->getValues();

    $config = $this->configFactory()->getEditable('conreg.settings.' . $eid);
    // If new add-on populated, create an empty array.
    if (!empty($vals['new_addon']['addon_name'])) {
      $config->set('add-ons.' . $vals['new_addon']['addon_name'], []);
    }
    // Loop through add-ons and save to config.
    foreach ($vals['addons'] ?? [] as $addOnId => $addOnVals) {
      $config->set('add-ons.' . $addOnId . '.addon.active', $addOnVals['addon']['active']);
      $config->set('add-ons.' . $addOnId . '.addon.global', $addOnVals['addon']['global']);
      $config->set('add-ons.' . $addOnId . '.addon.label', $addOnVals['addon']['label']);
      $config->set('add-ons.' . $addOnId . '.addon.description', $addOnVals['addon']['description']);
      $config->set('add-ons.' . $addOnId . '.addon.options', $addOnVals['addon']['options']);
      $config->set('add-ons.' . $addOnId . '.addon.weight', $addOnVals['addon']['weight']);
      $config->set('add-ons.' . $addOnId . '.info.label', $addOnVals['info']['label']);
      $config->set('add-ons.' . $addOnId . '.info.description', $addOnVals['info']['description']);
      $config->set('add-ons.' . $addOnId . '.free.label', $addOnVals['free']['label']);
      $config->set('add-ons.' . $addOnId . '.free.description', $addOnVals['free']['description']);
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
