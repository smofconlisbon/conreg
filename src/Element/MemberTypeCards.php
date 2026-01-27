<?php

namespace Drupal\conreg\Element;

use Drupal\Component\Utility\Html as HtmlUtility;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\Radios;

/**
 * Provides a member type card selection element.
 *
 * This element displays membership types as styled cards showing
 * title, description, and price.
 *
 * Properties:
 * - #options: An associative array of option values and labels.
 * - #member_types: Full member type objects with name, description, price.
 * - #currency_symbol: Currency symbol to display with prices.
 *
 * @FormElement("member_type_cards")
 */
#[FormElement('member_type_cards')]
class MemberTypeCards extends Radios {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $info = parent::getInfo();
    $info['#process'] = [
      [static::class, 'processMemberTypeCards'],
    ];
    $info['#theme_wrappers'] = ['member_type_cards'];
    // Remove the pre_render that adds fieldset wrapper.
    $info['#pre_render'] = [];
    $info['#member_types'] = [];
    $info['#disabled_options'] = [];
    $info['#currency_symbol'] = '';
    return $info;
  }

  /**
   * Expands the element into individual radio card elements.
   */
  public static function processMemberTypeCards(&$element, FormStateInterface $form_state, &$complete_form) {
    if (count($element['#options']) > 0) {
      $weight = 0;
      $child_attributes = $element['#attributes'] ?? [];
      // Prevent child elements from inheriting an aria-describedby attribute.
      if (isset($child_attributes['aria-describedby'])) {
        unset($child_attributes['aria-describedby']);
      }

      // Determine which card should be the initial roving tab stop:
      // the selected option, or else the first enabled option.
      $tabbable_key = NULL;
      foreach ($element['#options'] as $key => $choice) {
        $is_disabled = isset($element['#disabled_options'][$key]);
        if (!empty($element['#default_value']) && (string) $element['#default_value'] === (string) $key) {
          $tabbable_key = $key;
          break;
        }
        if ($tabbable_key === NULL && !$is_disabled) {
          $tabbable_key = $key;
        }
      }
      $element['#tabbable_key'] = $tabbable_key;

      foreach ($element['#options'] as $key => $choice) {
        $weight += 0.001;

        $element += [$key => []];
        // Generate unique ID for each radio button.
        $parents_for_id = array_merge($element['#parents'], [$key]);

        // Get full type data if available.
        $type_data = $element['#member_types'][$key] ?? NULL;

        $isDisabled = isset($element['#disabled_options'][$key]);
        $radioId = HtmlUtility::getUniqueId('edit-' . implode('-', $parents_for_id));
        $descriptionId = $radioId . '-desc';
        $radioAttributes = $child_attributes;
        if (!empty($type_data?->description)) {
          $radioAttributes['aria-describedby'] = $descriptionId;
        }
        // Focus moves via the visible card (see below), not the hidden
        // input, so keep the input out of the tab order.
        $radioAttributes['tabindex'] = '-1';

        $element[$key] += [
          '#type' => 'radio',
          '#title' => $type_data?->name ?? $choice,
          '#return_value' => $key,
          '#default_value' => $element['#default_value'] ?? FALSE,
          '#attributes' => $radioAttributes,
          '#parents' => $element['#parents'],
          '#id' => $radioId,
          '#ajax' => $element['#ajax'] ?? NULL,
          '#error_no_message' => TRUE,
          '#weight' => $weight,
          '#disabled' => $isDisabled,
          // Custom properties for the card template.
          '#card_description' => check_markup($type_data?->description ?? $choice, $type_data?->descriptionFormat ?? 'basic_html'),
          '#card_description_id' => $descriptionId,
          '#card_price' => $type_data?->price ?? '',
          '#currency_symbol' => $element['#currency_symbol'],
          '#card_disabled' => $isDisabled,
        ];
      }
    }
    return $element;
  }

}
