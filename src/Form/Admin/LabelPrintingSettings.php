<?php

declare(strict_types=1);

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\Service\LabelRenderer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configure global badge label printing settings.
 *
 * Unlike most of this module's admin forms, this is deliberately not
 * per-event: label size, field layout, and copy count describe the
 * physical printer setup for a convention, not registration data, so
 * they apply site-wide.
 */
final class LabelPrintingSettings extends ConfigFormBase {

  /**
   * The 8 selectable slots for each positionable field.
   *
   * Shared between this form and the print agent's layout model (see
   * docs/site-building/label-printing.md) - "none" means the field is
   * not printed at all.
   */
  protected const POSITIONS = [
    'top_left' => 'Top-left',
    'top_center' => 'Top-center',
    'top_right' => 'Top-right',
    'middle' => 'Middle',
    'bottom_left' => 'Bottom-left',
    'bottom_center' => 'Bottom-center',
    'bottom_right' => 'Bottom-right',
    'none' => "Don't print",
  ];

  /**
   * The positionable fields, keyed by config/API name, valued by label.
   */
  protected const FIELDS = [
    'badge_name' => 'Badge name',
    'member_number' => 'Member Number',
    'days_attending' => 'Days attending',
    'badge_type' => 'Badge type',
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventStorage $eventStorage,
    protected LabelRenderer $labelRenderer,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_label_printing_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['conreg.label_printing.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('conreg.label_printing.settings');

    $form['rendering_backend'] = [
      '#type' => 'item',
      '#markup' => $this->renderingBackendStatus(),
    ];

    $labelSizeStorage = $this->entityTypeManager->getStorage('conreg_label_size');
    $labelSizeOptions = [];
    foreach ($labelSizeStorage->loadMultiple() as $labelSize) {
      /** @var \Drupal\conreg\Entity\LabelSize $labelSize */
      $labelSizeOptions[$labelSize->id()] = $labelSize->label();
    }

    $form['label_size'] = [
      '#type' => 'select',
      '#title' => $this->t('Label size'),
      '#options' => $labelSizeOptions,
      '#empty_option' => $labelSizeOptions ? NULL : $this->t('- No label sizes defined -'),
      '#default_value' => $config->get('label_size'),
      '#required' => TRUE,
      '#description' => $this->t('Manage the list of available sizes on the <a href=":url">Label Sizes</a> tab.', [
        ':url' => Url::fromRoute('entity.conreg_label_size.collection')->toString(),
      ]),
    ];

    $form['copies'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of copies to print'),
      '#description' => $this->t('Print this many copies of each label, e.g. 2 for a double-sided badge.'),
      '#min' => 1,
      '#default_value' => $config->get('copies') ?: 1,
      '#required' => TRUE,
    ];

    $form['suppress_printing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Suppress printing'),
      '#description' => $this->t('Render and store labels but do not send them to the printer. Mainly for testing.'),
      '#default_value' => (bool) $config->get('suppress_printing'),
    ];

    $form['name_lines'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of lines for name'),
      '#description' => $this->t('The print agent picks the largest font that fits the badge name across up to this many lines.'),
      '#min' => 1,
      '#default_value' => $config->get('name_lines') ?: 2,
      '#required' => TRUE,
    ];

    $form['field_positions'] = [
      '#type' => 'details',
      '#title' => $this->t('Field positions'),
      '#description' => $this->t('Where each field prints on the label. Two fields cannot share the same position.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $positionOptions = array_map([$this, 't'], self::POSITIONS);
    foreach (self::FIELDS as $fieldName => $fieldLabel) {
      $form['field_positions'][$fieldName] = [
        '#type' => 'select',
        '#title' => $this->t('@field', ['@field' => $fieldLabel]),
        '#options' => $positionOptions,
        '#default_value' => $config->get('field_positions.' . $fieldName) ?: 'none',
      ];
    }

    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Delete print jobs after (days)'),
      '#description' => $this->t('Print jobs (including their rendered images) older than this are deleted automatically by cron, to keep the database from growing without bound.'),
      '#min' => 1,
      '#default_value' => $config->get('retention_days') ?: 30,
      '#required' => TRUE,
    ];

    $printerOptions = [];
    foreach ($this->entityTypeManager->getStorage('conreg_printer')->loadMultiple() as $printer) {
      /** @var \Drupal\conreg\Entity\Printer $printer */
      $printerOptions[$printer->id()] = $this->t('@name (@event)', [
        '@name' => $printer->label(),
        '@event' => $this->eventName((int) $printer->get('eid')->value),
      ]);
    }

    $form['#attached']['library'][] = 'conreg/conreg_label_preview';
    $form['#attached']['drupalSettings']['conreg']['labelPreviewUrl'] = Url::fromRoute('conreg_label_preview')->toString();
    $form['#attached']['drupalSettings']['conreg']['labelTestPrintUrl'] = Url::fromRoute('conreg_label_test_print')->toString();

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Preview'),
      '#description' => $this->t('Renders a sample label using the settings above as currently shown on this form (even if not yet saved), with no print agent involved.'),
      '#open' => TRUE,
    ];
    $form['preview']['test_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test name'),
      '#description' => $this->t('Try names of different lengths to see how the auto-fit sizing/line-splitting behaves.'),
      '#default_value' => 'Jane Doe',
    ];
    $form['preview']['controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['conreg-label-preview-controls']],
    ];
    $form['preview']['controls']['preview_button'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Preview'),
      '#attributes' => [
        'type' => 'button',
        'id' => 'conreg-label-preview-button',
        'class' => ['button'],
      ],
    ];
    $form['preview']['controls']['test_print_printer'] = [
      '#type' => 'select',
      '#title' => $this->t('Printer'),
      '#title_display' => 'invisible',
      '#options' => $printerOptions,
      '#empty_option' => $this->t('- Select a printer -'),
      '#access' => (bool) $printerOptions,
    ];
    $form['preview']['controls']['test_print_button'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Test print'),
      '#attributes' => [
        'type' => 'button',
        'id' => 'conreg-label-test-print-button',
        'class' => ['button'],
        'disabled' => !$printerOptions,
      ],
    ];
    if (!$printerOptions) {
      $form['preview']['no_printers'] = [
        '#markup' => '<p>' . $this->t('No printers are configured, so test printing is unavailable. Manage printers on the <a href=":url">Printers</a> tab.', [
          ':url' => Url::fromRoute('entity.conreg_printer.collection')->toString(),
        ]) . '</p>',
      ];
    }
    $form['preview']['preview_image'] = [
      '#type' => 'html_tag',
      '#tag' => 'img',
      '#attributes' => [
        'id' => 'conreg-label-preview-image',
        'alt' => $this->t('Label preview'),
      ],
    ];
    $form['preview']['test_print_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['id' => 'conreg-label-test-print-status'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $positions = $form_state->getValue('field_positions', []);
    $seen = [];
    foreach ($positions as $fieldName => $position) {
      if ($position === 'none') {
        continue;
      }
      if (isset($seen[$position])) {
        $form_state->setErrorByName(
          'field_positions][' . $fieldName,
          $this->t('@field and @other cannot both print at the same position.', [
            '@field' => self::FIELDS[$fieldName] ?? $fieldName,
            '@other' => self::FIELDS[$seen[$position]] ?? $seen[$position],
          ]),
        );
        continue;
      }
      $seen[$position] = $fieldName;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('conreg.label_printing.settings')
      ->set('label_size', $form_state->getValue('label_size'))
      ->set('copies', (int) $form_state->getValue('copies'))
      ->set('name_lines', (int) $form_state->getValue('name_lines'))
      ->set('suppress_printing', (bool) $form_state->getValue('suppress_printing'))
      ->set('field_positions', $form_state->getValue('field_positions', []))
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * A short status line naming the active rendering backend.
   *
   * Imagick decodes full Unicode (including emoji) correctly; the
   * GD fallback (used automatically when the `imagick` PHP extension
   * isn't installed) handles accented Latin/Cyrillic/Greek/etc. fine
   * but mis-renders emoji and other 4-byte-UTF-8 characters.
   */
  protected function renderingBackendStatus(): string {
    if ($this->labelRenderer->getActiveBackendName() === 'imagick') {
      return (string) $this->t('Rendering with <strong>ImageMagick</strong> - full Unicode support, including emoji.');
    }
    return (string) $this->t('Rendering with <strong>GD</strong> (fallback) - accented Latin, Cyrillic, Greek, and similar scripts render correctly, but emoji and some other characters may not. Install the PHP <code>imagick</code> extension for full Unicode support.');
  }

  /**
   * Resolves an event ID to its name, falling back to the raw ID.
   */
  protected function eventName(int $eid): string {
    foreach ($this->eventStorage->eventOptions() as $event) {
      if ((int) $event['eid'] === $eid) {
        return $event['event_name'];
      }
    }
    return (string) $eid;
  }

}
