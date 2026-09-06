<?php

namespace Drupal\conreg\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
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
      ->setSetting('max_length', 64);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Machine name'))
      ->setDescription(t('A stable, CUPS-safe identifier (e.g. "bilbo_baggins") matching the print agent\'s CUPS queue name. Sent over the API and matched against the --printer argument the agent polls with; never shown to staff.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['eid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Event ID'))
      ->setDescription(t('The event this printer is available for.'))
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
