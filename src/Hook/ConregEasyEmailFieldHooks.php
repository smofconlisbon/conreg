<?php

declare(strict_types=1);

namespace Drupal\conreg\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Adds the fields Easy Email templates need to resolve conreg tokens.
 *
 * Easy Email's EmailTokenEvaluator only ever passes the easy_email entity
 * itself as token data (see EmailTokenContext's docblock) - these fields
 * are how a `conreg_registration_*`/`conreg_member_check_*`/
 * `conreg_planz_invite_*`/`conreg_discord_invite_*` email instance carries
 * enough context for ConregTokenHooks/ConregPlanzHooks/
 * ConregDiscordTokenHooks to resolve their tokens.
 */
class ConregEasyEmailFieldHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'easy_email') {
      return [];
    }

    $fields = [];

    $fields['field_conreg_eid'] = BaseFieldDefinition::create('integer')
      ->setLabel($this->t('ConReg event ID'))
      ->setDescription($this->t('The event this email relates to, used to resolve [conreg:*] tokens.'))
      ->setCardinality(1);

    $fields['field_conreg_mid'] = BaseFieldDefinition::create('integer')
      ->setLabel($this->t('ConReg member ID(s)'))
      ->setDescription($this->t('The member(s) this email relates to, used to resolve [conreg:member:*]/[conreg:members:*] tokens. More than one value combines separate registration groups into one email.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['field_conreg_extra_token_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel($this->t('ConReg extra token data'))
      ->setDescription($this->t('JSON-encoded extra token data generated at send time (e.g. PlanZ/Discord invite details) that cannot be recomputed later from eid/mid alone.'))
      ->setCardinality(1);

    return $fields;
  }

}
