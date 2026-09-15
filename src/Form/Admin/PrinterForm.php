<?php

declare(strict_types=1);

namespace Drupal\conreg\Form\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Service\EventStorage;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Printer add/edit form.
 */
final class PrinterForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected EventStorage $eventStorage,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get(EventStorage::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\conreg\Entity\Printer $entity */
    $entity = $this->entity;

    $form = parent::form($form, $form_state);

    // Replace the default integer widget for "eid" with a select of
    // event names - printers belong to an event, but staff shouldn't
    // have to know event IDs.
    unset($form['eid']);

    $options = [];
    foreach ($this->eventStorage->eventOptions() as $event) {
      $options[$event['eid']] = $event['event_name'];
    }

    $form['event'] = [
      '#type' => 'select',
      '#title' => $this->t('Event'),
      '#options' => $options,
      '#default_value' => $entity->get('eid')->value,
      '#required' => TRUE,
      '#weight' => -10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state): Printer {
    /** @var \Drupal\conreg\Entity\Printer $entity */
    $entity = parent::buildEntity($form, $form_state);
    $entity->set('eid', (int) $form_state->getValue('event'));
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\conreg\Entity\Printer */
    $entity = $this->entity;
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new printer %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated printer %label.', $message_args),
      }
    );
    $form_state->setRedirect('entity.conreg_printer.collection');
    return $result;
  }

}
