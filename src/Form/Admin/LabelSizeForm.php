<?php

declare(strict_types=1);

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\Entity\LabelSize;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Label size add/edit form.
 */
final class LabelSizeForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\conreg\Entity\LabelSize $entity */
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
        'exists' => [LabelSize::class, 'load'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['width_mm'] = [
      '#type' => 'number',
      '#title' => $this->t('Width (mm)'),
      '#description' => $this->t('The label stock width, in millimeters.'),
      '#min' => 1,
      '#default_value' => $entity->getWidthMm() ?: NULL,
      '#required' => TRUE,
    ];

    $form['height_mm'] = [
      '#type' => 'number',
      '#title' => $this->t('Height (mm)'),
      '#description' => $this->t('The label stock height, in millimeters.'),
      '#min' => 1,
      '#default_value' => $entity->getHeightMm() ?: NULL,
      '#required' => TRUE,
    ];

    $form['rotate_degrees'] = [
      '#type' => 'select',
      '#title' => $this->t('Rotation'),
      '#description' => $this->t('Degrees the print agent rotates the rendered label before sending it to the printer. Most printers feed correctly at 90°; switch to -90° if labels come out upside down.'),
      '#options' => [
        90 => $this->t('90° (standard)'),
        -90 => $this->t('-90° (reversed feed)'),
      ],
      '#default_value' => $entity->getRotateDegrees(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\conreg\Entity\LabelSize $entity */
    $entity = $this->entity;
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new label size %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated label size %label.', $message_args),
      }
    );
    $form_state->setRedirect('entity.conreg_label_size.collection');
    return $result;
  }

}
