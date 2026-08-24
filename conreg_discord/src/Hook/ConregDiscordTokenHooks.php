<?php

declare(strict_types=1);

namespace Drupal\conreg_discord\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;

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

    foreach ($tokens as $name => $original) {
      if ($name === 'invite-url' && !empty($data['discord']['invite_url'])) {
        $replacements[$original] = $data['discord']['invite_url'];
      }
    }

    return $replacements;
  }

}
