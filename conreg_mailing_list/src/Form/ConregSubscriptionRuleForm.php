<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list\Form;

use Drupal\conreg\FieldOptions;
use Drupal\conreg\Service\ConregOptions;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\conreg_mailing_list\Entity\ConregSubscriptionRule;
use Drupal\conreg_mailing_list\Exception\MailingListException;
use Drupal\conreg_mailing_list\MailingListProviderPluginManager;
use Drupal\Core\Htmx\Htmx;

/**
 * Subscription rule form.
 */
final class ConregSubscriptionRuleForm extends EntityForm {

  public function __construct(
    protected MailingListProviderPluginManager $providerManager,
    protected ConregOptions $conregOptions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {

    $eid = (int) $this->getRouteMatch()->getParameter('eid');

    /** @var \Drupal\conreg_mailing_list\Entity\ConregSubscriptionRule */
    $entity = $this->entity;

    $form = parent::form($form, $form_state);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => [ConregSubscriptionRule::class, 'load'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $entity->Status(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $entity->getDescription(),
    ];

    $providers = array_map(fn($provider) => $provider['label'], $this->providerManager->getDefinitions());
    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('List provider'),
      '#options' => $providers,
      '#default_value' => $entity->getProvider(),
    ];

    $lists = [];
    $providerId = $form_state->getValue('provider', $entity->getProvider() ?: array_key_first($providers));
    if ($providerId) {
      /** @var \Drupal\conreg_mailing_list\MailingListProviderInterface */
      $provider = $this->providerManager->createInstance($providerId);
      try {
        $lists = $provider->getLists();
      }
      catch (MailingListException $e) {
        $this->messenger()->addWarning($this->t('Unable to fetch mailing lists from the provider: @message', ['@message' => $e->getMessage()]));
      }
    }
    $form_state->set('lists', $lists);
    $form['list_id'] = [
      '#type' => 'select',
      '#title' => $this->t('List ID'),
      '#options' => $lists,
      '#default_value' => $entity->getListId(),
      '#wrapper_attributes' => ['id' => 'models-wrapper'],
    ];

    (new Htmx())
      ->post()
      ->select('*:has(>select[name="list_id"])')
      ->target('*:has(>select[name="list_id"])')
      ->swap('outerHTML')
      ->applyTo($form['provider']);

    $methods = ['_any' => $this->t('Any')] + $this->conregOptions->communicationMethod($eid);
    $form['communication_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Communications method'),
      '#description' => $this->t('A communications method to add member to list for. Select "Any" to allow any method.'),
      '#options' => $methods,
      '#default_value' => $entity->getCommunicationMethod(),
    ];

    $fieldOptions = FieldOptions::getFieldOptions($eid);
    $options = [-1 => "Any"];
    foreach ($fieldOptions->options as $optId => $option) {
      $options[$optId] = $option->title;
    }
    $form['member_option'] = [
      '#type' => 'select',
      '#title' => $this->t('Member option'),
      '#description' => $this->t('A membership option to add member to the list for. Select "Any" to allow any option.'),
      '#options' => $options,
      '#default_value' => $entity->getMemberOption(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state): ConregSubscriptionRule {
    /** @var \Drupal\conreg_mailing_list\Entity\ConregSubscriptionRule $entity */
    $entity = parent::buildEntity($form, $form_state);

    if ($entity->isNew()) {
      $eid = (int) $this->getRouteMatch()->getParameter('eid');
      $entity->setEventId($eid);
    }

    $lists = $form_state->get('lists') ?? [];
    if (isset($lists[$entity->getListId()])) {
      $entity->set('list_name', (string) $lists[$entity->getListId()]);
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\conreg_mailing_list\Entity\ConregSubscriptionRule */
    $entity = $this->entity;
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new subscription rule %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated subscription rule %label.', $message_args),
      }
    );
    $form_state->setRedirect(
      'entity.conreg_subscription_rule.collection',
      ['eid' => $entity->getEventId()],
    );
    return $result;
  }

}
