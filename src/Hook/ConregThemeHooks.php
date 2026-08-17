<?php

declare(strict_types=1);

namespace Drupal\conreg\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Theme hook implementations for conreg.
 */
class ConregThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'member_type_cards' => [
        'render element' => 'element',
      ],
      'member_type_card' => [
        'render element' => 'element',
      ],
      'member_day_options' => [
        'render element' => 'element',
      ],
      'member_day_option' => [
        'render element' => 'element',
      ],
    ];
  }

  /**
   * Implements hook_preprocess_HOOK() for member_type_cards.
   */
  #[Hook('preprocess_member_type_cards')]
  public function preprocessMemberTypeCards(array &$variables): void {
    $element = $variables['element'];
    $current_value = $element['#value'] ?? $element['#default_value'] ?? NULL;

    $variables['cards'] = [];
    foreach ($element['#options'] as $key => $choice) {
      $child = $element[$key] ?? NULL;
      if ($child === NULL) {
        continue;
      }
      $suggestion = $this->buildThemeSuggestion($key);
      $isSelected = $current_value !== NULL && (string) $current_value === (string) $child['#return_value'];
      $variables['cards'][$key] = [
        '#theme' => ['member_type_card__' . $suggestion, 'member_type_card'],
        '#id' => $child['#id'],
        '#radio' => $child,
        '#title' => $child['#title'],
        '#return_value' => $child['#return_value'],
        '#card_description' => $child['#card_description'],
        '#card_description_id' => $child['#card_description_id'],
        '#card_price' => $child['#card_price'],
        '#currency_symbol' => $child['#currency_symbol'],
        '#card_disabled' => $child['#card_disabled'],
        '#is_selected' => $isSelected,
        '#is_tabbable' => (string) ($element['#tabbable_key'] ?? '') === (string) $child['#return_value'],
        // Only the selected card carries the day-options checkboxes, if the
        // selected type has any - see Registration::buildForm().
        '#day_options' => $isSelected ? ($element['day_options'] ?? NULL) : NULL,
      ];
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for member_day_options.
   */
  #[Hook('preprocess_member_day_options')]
  public function preprocessMemberDayOptions(array &$variables): void {
    $element = $variables['element'];

    $variables['options'] = [];
    foreach ($element['#options'] as $key => $choice) {
      $child = $element[$key] ?? NULL;
      if ($child === NULL) {
        continue;
      }
      $suggestion = $this->buildThemeSuggestion($key);
      $variables['options'][$key] = [
        '#theme' => ['member_day_option__' . $suggestion, 'member_day_option'],
        '#checkbox' => $child,
        '#day_description' => $child['#day_description'],
        '#day_price' => $child['#day_price'],
        '#currency_symbol' => $child['#currency_symbol'],
      ];
    }
  }

  /**
   * Builds a theme suggestion suffix from an option key.
   *
   * Lowercases the key and collapses non-alphanumeric characters to a single
   * underscore, so it's safe to use in a template filename suggestion.
   */
  private function buildThemeSuggestion(string|int $key): string {
    return mb_strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $key));
  }

}
