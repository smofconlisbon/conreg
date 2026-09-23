<?php

namespace Drupal\conreg\Entity;

use Drupal\conreg\Form\Admin\PrinterForm;
use Drupal\conreg\PrinterListBuilder;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the printer entity.
 *
 * A printer is a physical Dymo LabelWriter attached to a print-server
 * device, identified by the "fun name" stickered on the unit (e.g.
 * "Bilbo Baggins") that also doubles as its CUPS queue name.
 */
#[ContentEntityType(
  id: 'conreg_printer',
  label: new TranslatableMarkup('Printer'),
  base_table: 'conreg_printer',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  handlers: [
    'list_builder' => PrinterListBuilder::class,
    'form' => [
      'add' => PrinterForm::class,
      'edit' => PrinterForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/conreg/label-printing/printers',
    'add-form' => '/admin/config/conreg/label-printing/printers/add',
    'edit-form' => '/admin/config/conreg/label-printing/printers/{conreg_printer}/edit',
    'delete-form' => '/admin/config/conreg/label-printing/printers/{conreg_printer}/delete',
  ],
  admin_permission: 'manage convention members',
)]
class Printer extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The "fun name" stickered on the physical printer, e.g. "Bilbo Baggins". Shown to staff in the check-in printer picker.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -30,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Machine name'))
      ->setDescription(t('A stable, CUPS-safe identifier (e.g. "bilbo_baggins") matching the print agent\'s CUPS queue name. Sent over the API and matched against the --printer argument the agent polls with; never shown to staff.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['eid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Event ID'))
      ->setDescription(t('The event this printer is available for.'))
      ->setRequired(TRUE);

    $fields['last_seen'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last seen'))
      ->setDescription(t("When this printer's agent last successfully polled for jobs - the poll itself is the heartbeat signal."));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
