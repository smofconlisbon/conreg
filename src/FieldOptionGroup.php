<?php

namespace Drupal\conreg;

/**
 * Class to represent a group of options.
 */
class FieldOptionGroup {

  /**
   * Array of field options in this group.
   *
   * @var \Drupal\conreg\FieldOption[]
   */
  public array $fieldOptions;

  /**
   * Group ID.
   *
   * @var string|int
   */
  public $groupId;

  /**
   * Type of field (e.g., checkboxes, textfields).
   *
   * @var string
   */
  public $fieldType;

  /**
   * Field name identifier.
   *
   * @var string
   */
  public $fieldName;

  /**
   * Human-readable title of the field group.
   *
   * @var string
   */
  public $title;

  /**
   * Array of options keyed by option ID.
   *
   * @var \Drupal\conreg\FieldOption[]
   */
  public $options;

  /**
   * Whether the field group is global.
   *
   * @var bool|string
   */
  public $global;

  /**
   * Whether the field group is public.
   *
   * @var bool|string
   */
  public $public;

  /**
   * Constructs a new FieldOptionGroup object.
   */
  public function __construct() {
    $this->fieldOptions = [];
  }

  /**
   * Convert group line into object fields.
   */
  public function parseGroup($groupLine) {
    [$this->groupId, $this->fieldType, $this->fieldName, $this->title, $this->global, $this->public] = array_pad(explode('|', $groupLine), 6, '');
  }

  /**
   * Make a copy of the group, but not its options.
   */
  public function cloneGroup() {
    $group = new FieldOptionGroup();
    $group->groupId = $this->groupId;
    $group->fieldType = $this->fieldType;
    $group->fieldName = $this->fieldName;
    $group->title = $this->title;
    $group->global = $this->global;
    $group->public = $this->public;
    return $group;
  }

  /**
   * Add an option to the group.
   */
  public function addOption(FieldOption &$option) {
    $this->options[$option->optionId] = $option;
  }

  /**
   * Create group form fields.
   */
  public function groupForm(&$member, $requireMandatory = TRUE) {
    $options = [
      '#type' => 'fieldset',
      '#title' => t($this->title),
      '#attributes' => [
        'class' => ['field-option-group'],
      ],
    ];
    switch ($this->fieldType) {
      case 'checkboxes':
        return $this->groupCheckBoxes($options, $member, $requireMandatory);

      case 'textfields':
        return $this->groupTextFields($options, $member);

      default:
        return $this->groupCheckBoxes($options, $member, $requireMandatory);
    }
  }

  /**
   * Create group check box fields.
   */
  private function groupCheckBoxes($options, &$member, $requireMandatory) {
    foreach ($this->options as $option) {
      // Create a div to contain the option.
      $options[$option->optionId] = [
        '#prefix' => '<div class="option_' . $option->optionId . '">',
        '#suffix' => '</div>',
      ];
      // Add the option to the form.
      $options[$option->optionId]['option'] = [
        '#type' => 'checkbox',
        '#title' => $option->title,
        '#attributes' => [
          'class' => ['field-option'],
        ],
      ];
      if (isset($option->mustSelect) && $option->mustSelect == 1 && $requireMandatory) {
        $options[$option->optionId]['option']['#required'] = TRUE;
        $options[$option->optionId]['option']['#attributes']['class'][] = 'must-select';
      }
      // If option has detail, add the detail.
      if (!empty($option->detailTitle)) {
        $options[$option->optionId]['option']['#attributes']['class'][] = 'field-option-has-detail';
        $options[$option->optionId]['detail'] = [
          '#type' => 'textfield',
          '#title' => $option->detailTitle,
          '#weight' => $option->weight,
          '#attributes' => [
            'class' => ['field-option-detail'],
          ],
        ];
        if ($option->detailRequired) {
          // $options[$option->optionId]['detail']['#required'] = TRUE;
          $options[$option->optionId]['detail']['#attributes']['class'][] = 'detail-required';
        }
      }
      // If member data for option, add the values.
      if (isset($member) && isset($member->options) && isset($member->options[$option->optionId])) {
        $options[$option->optionId]['option']['#default_value'] = $member->options[$option->optionId]->isSelected;
        if (isset($member->options[$option->optionId]->optionDetail)) {
          $options[$option->optionId]['detail']['#default_value'] = $member->options[$option->optionId]->optionDetail;
        }
      }
    }
    return $options;
  }

  /**
   * Create group text fields.
   */
  private function groupTextFields($options, &$member) {
    foreach ($this->options as $option) {
      // Create a div to contain the option.
      $options[$option->optionId] = [
        '#prefix' => '<div class="option_' . $option->optionId . '">',
        '#suffix' => '</div>',
      ];
      // If option has detail, add the detail.
      $options[$option->optionId]['detail'] = [
        '#type' => 'textfield',
        '#title' => $option->detailTitle,
        '#weight' => $option->weight,
        '#attributes' => [
          'class' => ['field-option-detail'],
        ],
      ];
      if ($option->detailRequired) {
        $options[$option->optionId]['detail']['#required'] = TRUE;
        $options[$option->optionId]['detail']['#attributes']['class'][] = 'detail-required';
      }
      // If member data for option, add the values.
      if (isset($member->options[$option->optionId]->optionDetail)) {
        $options[$option->optionId]['detail']['#default_value'] = $member->options[$option->optionId]->optionDetail;
      }
    }
    return $options;
  }

  /**
   * Create a new option group.
   */
  public static function newGroup($groupLine) {
    if (!empty($groupLine)) {
      $group = new FieldOptionGroup();
      $group->parseGroup($groupLine);
      return $group;
    }
    return FALSE;
  }

}
