<?php

declare(strict_types=1);

namespace Drupal\conreg\Form\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Service\EventStorage;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Printer add/edit form.
 */
final class PrinterForm extends ContentEntityForm {

  /**
   * Storage for conreg_printer entities, used by machineNameExists().
   *
   * Named distinctly (rather than reusing EntityForm's own untyped
   * $entityTypeManager property, which Drupal populates automatically
   * via setEntityTypeManager() once this form is retrieved the normal
   * way) so this works the same regardless of how the form object was
   * instantiated - including directly via ::create(), as tests do.
   */
  protected EntityStorageInterface $printerStorage;

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected EventStorage $eventStorage,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->printerStorage = $entity_type_manager->getStorage('conreg_printer');
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
      $container->get('entity_type.manager'),
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

    // Auto-suggest the machine name from the Name field as the admin
    // types, the same live-preview UX Drupal core uses for e.g. content
    // type/view mode machine names - retrofitted onto machine_name's
    // field widget by swapping its plain textfield for core's
    // #type => 'machine_name' element. This works because "name" (the
    // source) is built above with a lower #weight than "machine_name",
    // so it's already processed - and therefore has an #id to source
    // from - by the time machine_name's #process callback runs.
    $form['machine_name']['widget'][0]['value']['#type'] = 'machine_name';
    $form['machine_name']['widget'][0]['value']['#machine_name'] = [
      'source' => ['name', 'widget', 0, 'value'],
      'exists' => [$this, 'machineNameExists'],
    ];

    return $form;
  }

  /**
   * Callback for #machine_name['exists']: is $value already used by a printer?
   *
   * Checked globally, not scoped to one event: machine_name doubles as
   * the physical printer's CUPS queue name (see its field description
   * on Printer::baseFieldDefinitions()), so it needs to be unique across
   * the whole print-server fleet, not just within one event.
   */
  public function machineNameExists(string $value, array $element, FormStateInterface $form_state): bool {
    $query = $this->printerStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $value);
    if (!$this->entity->isNew()) {
      $query->condition('id', $this->entity->id(), '<>');
    }
    return (bool) $query->count()->execute();
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
    $message_args = ['%label' => $entity->label() ?? ''];
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
