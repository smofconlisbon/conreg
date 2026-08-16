<?php

namespace Drupal\conreg_mailerlite\Form;

use Drupal\conreg_mailing_list\Exception\MailingListException;
use Drupal\conreg_mailing_list\MailingListProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Configure the shared MailerLite credentials for all events.
 */
class ConfigMailerliteForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected MailingListProviderPluginManager $providerManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_config_mailerlite';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['conreg_mailerlite.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('conreg_mailerlite.settings');

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Mailerlite API key'),
      '#description' => $this->t('The Key containing your Mailerlite API token (Integrations &rarr; API).'),
      '#key_filters' => ['type_group' => 'authentication'],
      '#default_value' => $config->get('api_key') ?: '',
    ];

    if ($config->get('api_key')) {
      $form['api_key_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Key status'),
        '#markup' => $this->getKeyStatusMessage(),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('conreg_mailerlite.settings')
      ->set('api_key', trim((string) $form_state->getValue('api_key')))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Checks the currently configured key against the real MailerLite API.
   */
  protected function getKeyStatusMessage(): TranslatableMarkup {
    try {
      $count = count($this->providerManager->createInstance('mailerlite')->getLists());
      return $this->t('Valid - @count mailing lists found.', ['@count' => $count]);
    }
    catch (MailingListException) {
      return $this->t('Invalid key.');
    }
  }

}
