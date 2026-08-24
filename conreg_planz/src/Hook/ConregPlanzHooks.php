<?php

namespace Drupal\conreg_planz\Hook;

use Drupal\conreg\Member;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for conreg_planz.
 */
class ConregPlanzHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_convention_member_inserted().
   */
  #[Hook('convention_member_inserted')]
  public static function conventionMemberInserted(Member $member): void {
    _conreg_planz_check_user($member);
  }

  /**
   * Implements hook_convention_member_updated().
   */
  #[Hook('convention_member_updated')]
  public static function conventionMemberUpdated(Member $member): void {
    _conreg_planz_check_user($member);
  }

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo() {
    $info = [];
    $info['types']['conreg-planz'] = [
      'name' => $this->t('ConReg PlanZ'),
      'description' => $this->t('PlanZ/Zambia invite tokens.'),
    ];
    $info['tokens']['conreg-planz']['user'] = [
      'name' => $this->t('PlanZ user'),
      'description' => $this->t("The member's PlanZ/Zambia badge ID."),
    ];
    $info['tokens']['conreg-planz']['password'] = [
      'name' => $this->t('PlanZ password'),
      'description' => $this->t('The generated PlanZ/Zambia password, when set.'),
    ];
    $info['tokens']['conreg-planz']['url'] = [
      'name' => $this->t('PlanZ URL'),
      'description' => $this->t('The PlanZ/Zambia site URL.'),
    ];
    return $info;
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
    $replacements = [];
    if ($type != 'conreg-planz') {
      return $replacements;
    }

    foreach ($tokens as $name => $original) {
      if (in_array($name, ['user', 'password', 'url'], TRUE) && !empty($data['planz'][$name])) {
        $replacements[$original] = $data['planz'][$name];
      }
    }

    return $replacements;
  }

}
