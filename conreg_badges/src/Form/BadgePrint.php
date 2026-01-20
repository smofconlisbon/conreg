<?php

namespace Drupal\conreg_badges\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\conreg\ConregConfig;
use Drupal\conreg\ConregOptions;
use Drupal\conreg\FieldOptionStorage;
use Drupal\conreg\Service\MemberStorage;
use Drupal\Core\DependencyInjection\AutowireTrait;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class BadgePrint extends FormBase {

  use AutowireTrait;

  /**
   * Construct the form.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_badges';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    $config = ConregConfig::getConfig($eid);
    $badgeTypes = ConregOptions::badgeTypes($eid, $config);
    $days = ConregOptions::days($eid, $config);
    $countryOptions = ConregOptions::memberCountries($eid, $config);
    $digits = $config->get('member_no_digits');

    // Get any existing form values for use in AJAX validation.
    $form_values = $form_state->getValues();

    if ($select_by_date = (isset($form_values['view']['by_date'])) ? $form_values['view']['by_date'] : 0) {

    }
    if ($select_by_memberno = (isset($form_values['view']['by_member_no'])) ? $form_values['view']['by_member_no'] : 0) {

    }
    if ($select_by_name = (isset($form_values['view']['by_name'])) ? $form_values['view']['by_name'] : 0) {

    }
    if ($select_max = (isset($form_values['view']['max_badges'])) ? $form_values['view']['max_badges'] : 1) {
      $max_num_badges = ($form_values['view']['max']['max_num_badges'] ?? 10);
    }
    if ($select_blanks = (isset($form_values['view']['blanks'])) ? $form_values['view']['blanks'] : 0) {

    }

    $form['#attached'] = [
      'library' => ['conreg/conreg_badges'],
    ];
    $form['#tree'] = TRUE;

    $form['upload'] = [
      '#prefix' => '<div id="upload">',
      '#suffix' => '</div>',
    ];
    $form['upload']['ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('IDs to upload'),
    ];
    $form['upload']['canvas'] = [
      '#prefix' => '<div id="canvas">',
      '#suffix' => '</div>',
    ];
    $form['upload']['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Image data'),
    ];

    $form['view'] = [
      '#prefix' => '<div id="badge-form">',
      '#suffix' => '</div>',
    ];
    $form['view']['by_date'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Select by date updated'),
      '#default_value' => $select_by_date,
      '#ajax' => [
        'wrapper' => 'badge-form',
        'callback' => [$this, 'updateForm'],
        'event' => 'change',
      ],
    ];

    if ($select_by_date) {
      $form['view']['date'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Choose dates'),
      ];
      $form['view']['date']['date_from'] = [
        '#type' => 'datetime',
        '#title' => $this->t('From'),
      ];
      $form['view']['date']['date_to'] = [
        '#type' => 'datetime',
        '#title' => $this->t('To'),
      ];
    }

    $form['view']['by_member_no'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Select by member number'),
      '#default_value' => $select_by_memberno,
      '#ajax' => [
        'wrapper' => 'badge-form',
        'callback' => [$this, 'updateForm'],
        'event' => 'change',
      ],
    ];
    if ($select_by_memberno) {
      $form['view']['number'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Choose member numbers'),
      ];
      $form['view']['number']['member_no_range'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Member number range'),
        '#description' => $this->t('Enter Member Nos to print badges for. Use commas (,) to separate ranges and hyphens (-) to separate range limits, e.g. "1,3,5-7".'),
      ];
    }

    $form['view']['by_name'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Select by name'),
    ];
    if ($select_by_name) {
      $form['view']['name'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Find members containing text'),
      ];
      $form['view']['name']['search'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Search text'),
      ];
    }

    $form['view']['max_badges'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Limit maximum number of badges'),
      '#default_value' => TRUE,
    ];
    if ($select_max) {
      $form['view']['max'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Maximum number of badges to print'),
      ];
      $form['view']['max']['max_num_badges'] = [
        '#type' => 'number',
        '#title' => $this->t('Limit number of badges printed to'),
        '#default_value' => $max_num_badges,
      ];
    }

    $form['view']['blanks'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add blank badges'),
      '#default_value' => $select_by_memberno,
      '#ajax' => [
        'wrapper' => 'badge-form',
        'callback' => [$this, 'updateForm'],
        'event' => 'change',
      ],
    ];
    if ($select_blanks) {
      $form['view']['num_blanks'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Specify blank badge quantity'),
      ];
      $form['view']['num_blanks']['num'] = [
        '#type' => 'number',
        '#title' => $this->t('Number of blank badges to add'),
      ];
    }

    $form['view']['update'] = [
      '#type' => 'button',
      '#value' => $this->t('Update'),
    ];

    $form['view']['do_upload'] = [
      '#type' => 'button',
      '#value' => $this->t('Upload Badge Images'),
      '#attributes' => ['onclick' => 'return (false);'],
    ];

    $options = [];
    if ($select_by_memberno) {
      $options['member_range'] = $form_values['view']['number']['member_no_range'] ?? '';
    }
    foreach ($this->memberStorage->adminMemberBadges($eid, $max_num_badges, $options) as $member) {
      $badge_type = $badgeTypes[$member['badge_type']] ?? $member['badge_type'];
      $member_no = $member['badge_type'] . sprintf("%0" . $digits . "d", $member['member_no']);
      $badge_name = Html::escape($member['badge_name']);
      if (!empty($member['days'])) {
        $dayDescriptions = [];
        foreach (explode('|', $member['days']) as $day) {
          $dayDescriptions[] = $days[$day] ?? $day;
        }
        $member_days = implode(', ', $dayDescriptions);
      }
      $country = (array_key_exists($member['country'], $countryOptions) ? $countryOptions[$member['country']] : $member['country']);
      $optionClasses = [];
      foreach (FieldOptionStorage::getMemberOptions($member['mid']) as $option) {
        $optionClasses[] = 'field-option-' . $option['optid'];
      }
      $form['member' . $member['mid']] = [
        '#markup' =>
        '<div id="mid' . $member['mid'] . '" class="badge badge-type-' . $member['badge_type'] . ' member-type-' . $member['member_type'] . ' ' . implode(' ', $optionClasses) . '">
  <div class="badge-side badge-left">
    <div class="badge-type">' . $badge_type . '</div>
    <div class="badge-number">' . $member_no . '</div>
    <div id="badge-name-mid' . $member['mid'] . '-left" class="badge-name">' . $badge_name . '</div>
    <div class="badge-days">' . $member_days . '</div>
    <div class="badge-country">' . $country . '</div>
  </div><div class="badge-side badge-right">
    <div class="badge-type">' . $badge_type . '</div>
    <div class="badge-number">' . $member_no . '</div>
    <div id="badge-name-mid' . $member['mid'] . '-right" class="badge-name">' . $badge_name . '</div>
    <div class="badge-days">' . $member_days . '</div>
    <div class="badge-country">' . $country . '</div>
  </div>
</div>',
      ];
    }

    if (isset($form_values['view']['num_blanks']['num']) && $form_values['view']['num_blanks']['num'] > 0) {
      for ($cnt = 0; $cnt < $form_values['view']['num_blanks']['num']; $cnt++) {
        $form['blank' . $cnt] = [
          '#markup' =>
          '<div id="blank' . $cnt . '" class="badge badge-blank">
  <div class="badge-side badge-left">
    <div id="badge-name-blank' . $cnt . '-left" class="badge-name"></div>
  </div><div class="badge-side badge-right">
    <div id="badge-name-blank' . $cnt . '-right" class="badge-name"></div>
  </div>
</div>',
        ];
      }
    }
    return $form;
  }

  /**
   * Callback function for "number of members" drop down.
   */
  public function updateForm(array $form, FormStateInterface $form_state) {
    // Form rebuilt with required number of members before callback.
    return $form['view'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
