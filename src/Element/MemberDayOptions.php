<?php

namespace Drupal\conreg\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\Checkboxes;

/**
 * Provides a member day-options selection element.
 *
 * This element displays a member type's per-day pricing options as
 * checkboxes, each showing its price and description.
 *
 * Properties:
 * - #options: An associative array of option values and labels.
 * - #day_data: Full day objects with name, description, and price.
 * - #currency_symbol: Currency symbol to display with prices.
 *
 * @FormElement("member_day_options")
 */
#[FormElement('member_day_options')]
class MemberDayOptions extends Checkboxes {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $info = parent::getInfo();
    $info['#process'] = [
      [static::class, 'processMemberDayOptions'],
    ];
    $info['#theme_wrappers'] = ['member_day_options'];
    // Remove the pre_render that adds fieldset wrapper.
    $info['#pre_render'] = [];
    $info['#day_data'] = [];
    $info['#currency_symbol'] = '';
    return $info;
  }

  /**
   * Expands the element into individual day checkbox elements.
   */
  public static function processMemberDayOptions(&$element, FormStateInterface $form_state, &$complete_form) {
    $element = parent::processCheckboxes($element, $form_state, $complete_form);

    foreach ($element['#options'] as $key => $choice) {
      $day_data = $element['#day_data'][$key] ?? NULL;

      $element[$key] += [
        // Custom properties for the day-option template.
        '#day_description' => $day_data?->description ?? $choice,
        '#day_price' => $day_data?->price ?? '',
        '#currency_symbol' => $element['#currency_symbol'],
      ];
      // The visible label (price + description) is built entirely by the
      // member-day-option template, so suppress the checkbox's own default
      // title/wrapper - only the bare <input> should render via #checkbox.
      $element[$key]['#title'] = NULL;
      $element[$key]['#theme_wrappers'] = [];
    }

    return $element;
  }

}
