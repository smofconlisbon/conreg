<?php

declare(strict_types=1);

namespace Drupal\conreg_discord\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\easy_email\Entity\EasyEmailInterface;

/**
 * Token hook implementations for conreg_discord.
 */
class ConregDiscordTokenHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo() {
    $info = [];
    $info['types']['conreg-discord'] = [
      'name' => $this->t('ConReg Discord'),
      'description' => $this->t('Discord invite tokens.'),
    ];
    $info['tokens']['conreg-discord']['invite-url'] = [
      'name' => $this->t('Invite URL'),
      'description' => $this->t('The Discord server invite URL.'),
    ];
    return $info;
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
    $replacements = [];
    if ($type != 'conreg-discord') {
      return $replacements;
    }

    // Easy Email's token evaluator only ever supplies 'easy_email' as
    // token data (see EmailTokenContext's docblock for the full
    // explanation) - derive 'discord' from the entity's
    // field_conreg_extra_token_data when the caller hasn't already
    // supplied it directly.
    if (!isset($data['discord']) && ($data['easy_email'] ?? NULL) instanceof EasyEmailInterface) {
      $email = $data['easy_email'];
      if ($email->hasField('field_conreg_extra_token_data') && !$email->get('field_conreg_extra_token_data')->isEmpty()) {
        $extra = json_decode($email->get('field_conreg_extra_token_data')->value, TRUE) ?? [];
        $data['discord'] = $extra['discord'] ?? [];
      }
    }

    foreach ($tokens as $name => $original) {
      if ($name === 'invite-url' && !empty($data['discord']['invite_url'])) {
        $replacements[$original] = $data['discord']['invite_url'];
      }
    }

    return $replacements;
  }

}
