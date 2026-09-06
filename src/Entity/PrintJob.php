<?php

namespace Drupal\conreg\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the print job entity.
 *
 * A print job is a request to print one badge label, queued for a
 * specific printer and claimed by exactly one print-server agent.
 * `member_name`/`member_number`/`days_attending` are a snapshot taken
 * at job-creation time rather than a live reference to the member
 * record, so a job stays correct even if the member is edited after
 * being queued but before it's printed.
 */
#[ContentEntityType(
  id: 'conreg_print_job',
  label: new TranslatableMarkup('Print job'),
  base_table: 'conreg_print_job',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  admin_permission: 'manage convention members',
)]
class PrintJob extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['eid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Event ID'))
      ->setRequired(TRUE);

    $fields['mid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Member ID'))
      ->setDescription(t('The member this label is for.'))
      ->setRequired(TRUE);

    $fields['member_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Member name'))
      ->setDescription(t('Badge name, snapshotted at job creation time.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['member_number'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Member number'))
      ->setSetting('max_length', 32);

    $fields['days_attending'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Days attending'))
      ->setSetting('max_length', 64);

    $fields['printer'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Printer'))
      ->setSetting('target_type', 'conreg_printer')
      ->setRequired(TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setSetting('allowed_values', [
        'pending' => 'Pending',
        'claimed' => 'Claimed',
        'done' => 'Done',
        'error' => 'Error',
      ])
      ->setDefaultValue('pending')
      ->setRequired(TRUE);

    $fields['message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Result message'))
      ->setDescription(t('Human-readable result reported back by the print agent.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
