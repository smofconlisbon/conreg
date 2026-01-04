<?php

namespace Drupal\conreg;

use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Component\Utility\Html;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class SimpleConregAdminMailoutEmails extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'conreg_admin_mailout_emails';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $eid = 1, $export = FALSE, $methods = NULL, $languages = NULL, $fields = NULL): Response|array {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);

    if ($export) {
      return $this->exportMemberEmail($eid, $methods, $languages, $fields);
    }

    $config = $this->config('conreg.settings.' . $eid);

    $form = [
      '#prefix' => '<div id="memberForm">',
      '#suffix' => '</div>',
    ];

    $methodOptions = ConregOptions::communicationMethod($eid, $config, TRUE);
    $form['communication_method'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Communications method'),
      '#options' => $methodOptions,
      '#ajax' => [
        'wrapper' => 'memberForm',
        'callback' => [$this, 'updateDisplayCallback'],
        'event' => 'change',
      ],
    ];

    $langOptions = $this->getLanguageOptions();
    $form['language'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Preferred languages'),
      '#options' => $langOptions,
      '#ajax' => [
        'wrapper' => 'memberForm',
        'callback' => [$this, 'updateDisplayCallback'],
        'event' => 'change',
      ],
    ];

    $fieldOptions = [
      'name' => $this->t('Name'),
      'method' => $this->t('Communications method'),
      'language' => $this->t('Language'),
    ];
    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Show additional fields'),
      '#options' => $fieldOptions,
      '#ajax' => [
        'wrapper' => 'memberForm',
        'callback' => [$this, 'updateDisplayCallback'],
        'event' => 'change',
      ],
    ];

    // Get any existing form values for use in AJAX validation.
    $form_values = $form_state->getValues();

    // Prepare export link.
    $exportMethods = implode('', array_filter($form_values['communication_method'] ?? []));

    $exportLanguages = implode('~', array_filter($form_values['language'] ?? []));

    $exportFields = (!empty($form_values['fields']['name']) ? 'N' : '')
      . (!empty($form_values['fields']['method']) ? 'M' : '')
      . (!empty($form_values['fields']['language']) ? 'L' : '');

    $exportUrl = Url::fromRoute('conreg_admin_mailout_emails_export',
                                [
                                  'eid' => $eid,
                                  'methods' => $exportMethods ?: '_',
                                  'languages' => $exportLanguages ?: '_',
                                  'fields' => $exportFields,
                                ],
                                ['absolute' => TRUE]);
    $exportLink = Link::fromTextAndUrl($this->t('Export Member Emails'), $exportUrl);

    $form['export']['link'] = [
      '#type' => 'markup',
      '#prefix' => '<div>',
      '#suffix' => '</div>',
      '#markup' => $exportLink->toString(),
    ];

    if (count($form_values)) {

      $showName = $form_values['fields']['name'];
      $showMethod = $form_values['fields']['method'];
      $showLanguage = $form_values['fields']['language'];

      $methods = [];
      foreach ($form_values['communication_method'] as $key => $val) {
        if ($val) {
          $methods[] = $key;
        }
      }

      $languages = [];
      foreach ($form_values['language'] as $key => $val) {
        if ($val) {
          $languages[] = $key;
        }
      }

      $headers = [];
      if ($showName) {
        $headers['first_name'] = ['data' => $this->t('First name'), 'field' => 'm.first_name'];
        $headers['last_name'] = ['data' => $this->t('Last name'), 'field' => 'm.last_name'];
      }
      $headers['email'] = ['data' => $this->t('Email'), 'field' => 'm.email'];
      if ($showMethod) {
        $headers['communication_method'] = ['data' => t('Communication method'), 'field' => 'm.communication_method'];
      }
      if ($showLanguage) {
        $headers['language'] = ['data' => $this->t('Language'), 'field' => 'm.language'];
      }

      $form['table'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#attributes' => ['id' => 'simple-conreg-admin-member-list'],
        '#empty' => t('No entries available.'),
        '#sticky' => TRUE,
      ];

      if (!empty($methods) && !empty($languages)) {
        // Fetch all entries for selected option or group.
        $mailoutMembers = ConregStorage::adminMailoutListLoad($eid, $methods, $languages);

        // Now loop through the combined results.
        foreach ($mailoutMembers as $entry) {
          $row = [];
          if ($showName) {
            $row['first_name'] = [
              '#markup' => Html::escape($entry['first_name']),
            ];
            $row['last_name'] = [
              '#markup' => Html::escape($entry['last_name']),
            ];
          }
          $row['email'] = [
            '#markup' => Html::escape($entry['email']),
          ];
          if ($showMethod) {
            $row['communication_method'] = [
              '#markup' => Html::escape($methodOptions[$entry['communication_method']]),
            ];
          }
          if ($showLanguage) {
            $row['language'] = [
              '#markup' => Html::escape($langOptions[$entry['language']]),
            ];
          }
          $form['table'][] = $row;
        }
      }
    }

    return $form;
  }

  /**
   * Callback function for "display" drop down.
   */
  public function updateDisplayCallback(array $form, FormStateInterface $form_state) {
    // Form rebuilt with required number of members before callback. Return new form.
    return $form;
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Convert quotes to double quotes, and wrap values containing quotes or commas in quotes for CSV output.
   *
   * @param string $value
   *
   * @return string
   */
  private function csvField(string $value): string {
    if (str_contains($value, '"')) {
      $value = str_replace('"', '""', $value);
    }
    if (str_contains($value, '"') || str_contains($value, ',')) {
      $value = '"' . $value . '"';
    }
    return $value;
  }

  /**
   * Export a file containing member emails.
   *
   * @param int $eid
   *   Event ID.
   * @param string $methods
   * @param string $languages
   * @param string $fields
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  private function exportMemberEmail(int $eid, string $methods, string $languages, string $fields): Response {
    // Split out parameters.
    $methods = str_split($methods);
    $languages = explode("~", $languages);
    $showName = str_contains($fields, "N");
    $showMethod = str_contains($fields, "M");
    $showLanguage = str_contains($fields, "L");

    $headerRow = '';
    if ($showName) {
      $headerRow .= $this->t('First name') . ',' . $this->t('Last name') . ',';
    }
    $headerRow .= $this->t('Email');
    if ($showMethod) {
      $headerRow .= ',' . t('Communication method');
    }
    if ($showLanguage) {
      $headerRow .= ',' . t('Language');
    }

    // Fetch all entries for selected option or group.
    $mailoutMembers = ConregStorage::adminMailoutListLoad($eid, $methods, $languages);

    $config = $this->config('conreg.settings.' . $eid);
    $methodOptions = ConregOptions::communicationMethod($eid, $config, TRUE);
    $langOptions = $this->getLanguageOptions();

    $output = $headerRow . "\n";

    if (!empty($methods) && !empty($languages)) {
      // Fetch all entries for selected option or group.
      $mailoutMembers = ConregStorage::adminMailoutListLoad($eid, $methods, $languages);

      // Now loop through the combined results.
      foreach ($mailoutMembers as $entry) {
        $expRow = [];
        if ($showName) {
          $expRow[] = $this->csvField($entry['first_name']);
          $expRow[] = $this->csvField($entry['last_name']);
        }
        $expRow[] = $this->csvField($entry['email']);
        if ($showMethod) {
          $expRow[] = $this->csvField($methodOptions[$entry['communication_method']]);
        }
        if ($showLanguage) {
          $expRow[] = $this->csvField($langOptions[$entry['language']]);
        }
        $output .= implode(',', $expRow) . "\n";
      }
    }

    $response = new Response($output);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename=member_emails.csv');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

  /**
   * Get an array of language names indexed by language code for active languages in Drupal.
   *
   * @return array
   */
  public function getLanguageOptions(): Array {
    $languages = \Drupal::languageManager()->getLanguages();
    $langOptions = [];
    foreach ($languages as $language) {
      $langOptions[$language->getId()] = $language->getName();
    }
    return $langOptions;
  }

}
