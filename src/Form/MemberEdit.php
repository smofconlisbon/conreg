<?php

namespace Drupal\conreg\Form;

use Drupal\conreg\Addons;
use Drupal\conreg\ConregOptions;
use Drupal\conreg\ConregStorage;
use Drupal\conreg\FieldOptions;
use Drupal\conreg\Member;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Allow member to update their own membership details.
 */
class MemberEdit extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'member_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1, $mid = NULL) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    // Get any existing form values for use in AJAX validation.
    $form_values = $form_state->getValues();

    // Get event configuration from config.
    $config = $this->config('conreg.settings.' . $eid);

    $types = ConregOptions::memberTypes($eid, $config);
    $memberClasses = ConregOptions::memberClasses($eid, $config);
    $days = ConregOptions::days($eid, $config);
    [$addOnOptions] = ConregOptions::memberAddons($eid, $config);
    $countryOptions = ConregOptions::memberCountries($eid, $config);
    $defaultCountry = $config->get('reference.default_country');

    // Load the member record.
    $member = Member::loadMember($mid);

    // Check member exists.
    if (!is_object($member) || empty($member->mid) || $member->eid != $eid || !$member->is_paid) {
      // Member not in database. Display error.
      $form['error'] = [
        '#markup' => $this->t("Sorry, we couldn't find that member."),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
      return $form;
    }
    // Get member class for selected member type.
    $memberType = $member->member_type;
    $curMemberClassRef = $types->types[$memberType]->memberClass ?? array_key_first($memberClasses->classes);
    $curMemberClass = $memberClasses->classes[$curMemberClassRef];

    // Check out who is editing.
    $user = \Drupal::currentUser();
    $email = $user->getEmail();

    // Get the member with the matching email address.
    $owner = ConregStorage::load([
      'eid' => $eid,
      'email' => $email,
      'mid' => $member->lead_mid,
      'is_paid' => 1,
    ]);
    // If couldn't find member with matching Lead MID, check on MID, as editor
    // may not be group leader.
    if (!is_array($owner)) {
      $owner = ConregStorage::load([
        'eid' => $eid,
        'email' => $email,
        'mid' => $mid,
        'is_paid' => 1,
      ]);
    }
    if (!is_array($owner)) {
      // Member not in database. Display error.
      $form['error'] = [
        '#markup' => $this->t('Sorry, you don\'t have permission to do that.'),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
      return $form;
    }

    $form = [
      '#tree' => TRUE,
      '#prefix' => '<div id="regform">',
      '#suffix' => '</div>',
      '#attached' => [
        'library' => ['conreg/conreg_form', 'conreg/conreg_field_options'],
      ],
    ];

    $form['member'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Member details'),
    ];

    $form['member']['intro'] = [
      '#markup' => $config->get('member_edit.intro_text'),
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    $form['member']['member_no'] = [
      '#markup' => $this->t('Member number: @member_no', ['@member_no' => $member->member_no]),
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    $form['member']['first_name'] = [
      '#markup' => $curMemberClass->fields->first_name . ': ' . $member->first_name,
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    $form['member']['last_name'] = [
      '#markup' => $curMemberClass->fields->last_name . ': ' . $member->last_name,
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    if (!$config->get('member_edit.email_editable')) {
      $form['member']['email'] = [
        '#markup' => $curMemberClass->fields->email . ': ' . $member->email,
        '#prefix' => '<div>',
        '#suffix' => '</div>',
      ];
    }

    if (!$config->get('member_edit.badge_name_editable')) {
      $form['member']['badge_name'] = [
        '#markup' => $curMemberClass->fields->badge_name . ': ' . $member->badge_name,
        '#prefix' => '<div>',
        '#suffix' => '</div>',
      ];
    }

    $form['member']['type'] = [
      '#markup' => $curMemberClass->fields->membership_type . ': ' . $types->privateOptions[$member->member_type],
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    $dayVals = '';
    $sep = '';
    if (isset($member->days)) {
      foreach (explode('|', $member->days) as $day) {
        $dayVals .= $sep . $days[$day];
        $sep = ', ';
      }
    }
    $form['member']['days'] = [
      '#markup' => $this->t('Days: %days', ['%days' => $dayVals]),
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    if ($config->get('member_edit.email_editable')) {
      $form['member']['email'] = [
        '#type' => 'email',
        '#title' => $curMemberClass->fields->email,
        '#default_value' => $member->email ?: '',
      ];
    }

    if ($config->get('member_edit.badge_name_editable')) {
      $badgename_max_length = $curMemberClass->max_length->badge_name;
      $form['member']['badge_name'] = [
        '#type' => 'textfield',
        '#title' => $curMemberClass->fields->badge_name,
        '#default_value' => $member->badge_name,
        '#required' => TRUE,
        '#maxlength' => $badgename_max_length ?: 128,
        '#attributes' => [
          'id' => "edit-members-badge-name",
          'class' => ['edit-members-badge-name'],
        ],
      ];
    }

    if (!empty($curMemberClass->fields->display)) {
      $form['member']['display'] = [
        '#type' => 'select',
        '#title' => $curMemberClass->fields->display,
        '#description' => $this->t('Select how you would like to appear on the membership list.'),
        '#options' => ConregOptions::display(),
        '#default_value' => $member->display ?: 'F',
        '#required' => TRUE,
      ];
    }

    if (!empty($curMemberClass->fields->communication_method)) {
      $form['member']['communication_method'] = [
        '#type' => 'select',
        '#title' => $curMemberClass->fields->communication_method,
        '#description' => $curMemberClass->fields->communication_method_description,
        '#options' => ConregOptions::communicationMethod($eid, $config, FALSE),
        '#default_value' => ($member->communication_method ?? 'E'),
        '#required' => TRUE,
      ];
    }

    if (!empty($curMemberClass->fields->street)) {
      $form['member']['street'] = [
        '#type' => 'textfield',
        '#title' => $curMemberClass->fields->street,
        '#default_value' => $member->street,
      ];
    }

    if (!empty($curMemberClass->fields->street2)) {
      $form['member']['street2'] = [
        '#type' => 'textfield',
        '#title' => $curMemberClass->fields->street2,
        '#default_value' => $member->street2,
      ];
    }

    if (!empty($curMemberClass->fields->city)) {
      $form['member']['city'] = [
        '#type' => 'textfield',
        '#title' => $curMemberClass->fields->city,
        '#default_value' => $member->city,
      ];
    }

    if (!empty($curMemberClass->fields->county)) {
      $form['member']['county'] = [
        '#type' => 'textfield',
        '#title' => $curMemberClass->fields->county,
        '#default_value' => $member->county,
      ];
    }

    if (!empty($curMemberClass->fields->postcode)) {
      $form['member']['postcode'] = [
        '#type' => 'textfield',
        '#title' => $curMemberClass->fields->postcode,
        '#default_value' => $member->postcode,
      ];
    }

    if (!empty($curMemberClass->fields->country)) {
      $form['member']['country'] = [
        '#type' => 'select',
        '#title' => $curMemberClass->fields->country,
        '#options' => $countryOptions,
        '#default_value' => $member->country ?: $defaultCountry,
        '#required' => TRUE,
      ];
    }

    if (!empty($curMemberClass->fields->phone)) {
      $form['member']['phone'] = [
        '#type' => 'tel',
        '#title' => $curMemberClass->fields->phone,
        '#default_value' => $member->phone,
      ];
    }

    if (!empty($curMemberClass->fields->birth_date)) {
      $form['member']['birth_date'] = [
        '#type' => 'date',
        '#title' => $curMemberClass->fields->birth_date,
        '#default_value' => $member->birth_date,
      ];
    }

    if (!empty($curMemberClass->fields->age)) {
      $form['member']['age'] = [
        '#type' => 'number',
        '#title' => $curMemberClass->fields->age,
        '#default_value' => $member->age,
      ];
    }

    // Get member add-on details.
    $addon = $form_values['member']['add_on'] ?? [];
    $form['member']['add_on'] = Addons::getAddon(
      $config,
      $addon,
      $addOnOptions,
      -1,
      [$this, 'updateMemberPriceCallback'],
      $form_state,
      $mid);

    // Get field options from form state. If not set, get from config.
    $fieldOptions = $form_state->get('fieldOptions');
    if (is_null($fieldOptions)) {
      $fieldOptions = FieldOptions::getFieldOptions($eid);
    }
    // Add the field options to the form. Display both global and member fields.
    // Display only public fields.
    $fieldOptions->addOptionFields($curMemberClassRef, $form['member'], $member, NULL, FALSE);

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save member'),
    ];

    $form['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'cancel',
      '#submit' => [[$this, 'submitCancel']],
      '#limit_validation_errors' => [],
    ];

    $form_state->set('member', $member);
    $form_state->set('mid', $mid);
    $form_state->set('member_class', $curMemberClassRef);
    $form_state->set('badgename_max_length', $badgename_max_length);
    return $form;
  }

  /**
   * Callback for "member type" and "add-on" drop-downs. Replace price fields.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state information.
   */
  public function updateMemberPriceCallback(array $form, FormStateInterface $form_state) {
    $addons = $form_state->get('addons') ?? [];
    $ajax_response = new AjaxResponse();
    // @todo Add-ons not currently displayed on member edit form. Add here.
    foreach ($addons as $addOnId) {
      if (!empty($form['member']['add_on'][$addOnId]['extra'])) {
        $id = '#member_addon_' . $addOnId . '_info';
        $ajax_response->addCommand(new HtmlCommand($id, \Drupal::service('renderer')->render($form['member']['add_on'][$addOnId]['extra']['info'])));
      }
    }

    return $ajax_response;
  }

  /**
   * Validate form before submit.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Submit handler for cancel button.
   */
  public function submitCancel(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    // Get session state to return to correct page.
    $tempstore = \Drupal::service('tempstore.private')->get('conreg');
    $display = $tempstore->get('display');
    $page = $tempstore->get('page');
    // Redirect to member list.
    $form_state->setRedirect('conreg_portal', ['eid' => $eid]);
  }

  /**
   * Submit handler for member edit form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $mid = $form_state->get('mid');
    $curMemberClassRef = $form_state->get('member_class');
    $member = $form_state->get('member');

    $config = $this->config('conreg.settings.' . $eid);
    $form_values = $form_state->getValues();

    // Get field options from form state. If not set, get from config.
    $fieldOptions = $form_state->get('fieldOptions');
    if (is_null($fieldOptions)) {
      $fieldOptions = FieldOptions::getFieldOptions($eid);
    }
    // Process option fields to remove any modifications from form values.
    $fieldOptions->processOptionFields($curMemberClassRef, $form_values['member'], $mid, $member->options);

    // Save the submitted entry.
    if (isset($form_values['member']['email'])) {
      $member->email = trim($form_values['member']['email']);
    }
    if (isset($form_values['member']['badge_name'])) {
      $member->badge_name = trim($form_values['member']['badge_name']);
    }
    if (isset($form_values['member']['display'])) {
      $member->display = $form_values['member']['display'];
    }
    if (isset($form_values['member']['communication_method'])) {
      $member->communication_method = $form_values['member']['communication_method'];
    }
    if (isset($form_values['member']['street'])) {
      $member->street = trim($form_values['member']['street']);
    }
    if (isset($form_values['member']['street2'])) {
      $member->street2 = trim($form_values['member']['street2']);
    }
    if (isset($form_values['member']['city'])) {
      $member->city = trim($form_values['member']['city']);
    }
    if (isset($form_values['member']['county'])) {
      $member->county = trim($form_values['member']['county']);
    }
    if (isset($form_values['member']['postcode'])) {
      $member->postcode = trim($form_values['member']['postcode']);
    }
    if (isset($form_values['member']['country'])) {
      $member->country = trim($form_values['member']['country']);
    }
    if (isset($form_values['member']['phone'])) {
      $member->phone = trim($form_values['member']['phone']);
    }
    if (isset($form_values['member']['birth_date'])) {
      $member->birth_date = $form_values['member']['birth_date'];
    }
    if (isset($form_values['member']['age'])) {
      $member->age = $form_values['member']['age'];
    }

    $return = $member->saveMember();

    // All members saved. Now save any add-ons.
    // Addons::saveMemberAddons($config, $form_values, $mid);.
    if ($return) {

      // Get session state to return to correct page.
      $tempstore = \Drupal::service('tempstore.private')->get('conreg');
      $display = $tempstore->get('display');
      $page = $tempstore->get('page');

      // Redirect to member list.
      $form_state->setRedirect('conreg_portal', ['eid' => $eid]);
    }
  }

}
