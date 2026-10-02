<?php

declare(strict_types=1);

namespace Drupal\conreg\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A price for one member type, or one of its days, as used by rate plans.
 *
 * Holds the planned price, and the price the member type or day had before the
 * plan was applied (NULL until then). The day is NULL for the member type's
 * main price. The member type and day names are recorded when the plan is
 * applied (NULL until then), so the record isn't changed by later renames.
 */
#[FieldType(
  id: 'conreg_member_type_price',
  label: new TranslatableMarkup('Member type price'),
  description: new TranslatableMarkup('A price for a member type.'),
  no_ui: TRUE,
)]
class MemberTypePriceItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['member_type'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Member type code'))
      ->setRequired(TRUE);
    $properties['day'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Day code, or NULL for the main price'));
    $properties['price'] = DataDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Price'))
      ->setRequired(TRUE);
    $properties['previous_price'] = DataDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Price before the plan was applied'));
    $properties['member_type_name'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Member type name when the plan was applied'));
    $properties['day_name'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Day name when the plan was applied'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'member_type';
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'member_type' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'day' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => FALSE,
        ],
        'price' => [
          'type' => 'numeric',
          'precision' => 10,
          'scale' => 2,
          'not null' => TRUE,
        ],
        'previous_price' => [
          'type' => 'numeric',
          'precision' => 10,
          'scale' => 2,
          'not null' => FALSE,
        ],
        'member_type_name' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'day_name' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return $this->get('member_type')->getValue() === NULL || $this->get('member_type')->getValue() === '';
  }

}
