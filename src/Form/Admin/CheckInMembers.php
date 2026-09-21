<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\conreg\ConregConfig;
use Drupal\conreg\ConregTable;
use Drupal\conreg\Payment;
use Drupal\conreg\PaymentLine;
use Drupal\conreg\Pricing\PricingContext;
use Drupal\conreg\Pricing\PricingSubject;
use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Service\PaymentStorage;
use Drupal\conreg\Service\PricingServiceInterface;
use Drupal\conreg\Service\PrintJobManager;
use Drupal\conreg\TableRole;
use Drupal\conreg\Trait\PrinterSessionTrait;
use Drupal\conreg\Trait\ShowBadgeNumberTrait;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;

/**
 * Simple form to add an entry, with all the interesting fields.
 */
class CheckInMembers extends FormBase {

  use AutowireTrait, ShowBadgeNumberTrait, PrinterSessionTrait;

  /**
   * Construct the form.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\conreg\Service\EventStorage $eventStorage
   *   The event storage service.
   * @param \Drupal\conreg\Service\PaymentStorage $paymentStorage
   *   The payment storage service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The site's language manager.
   * @param \Drupal\conreg\Service\PricingServiceInterface $pricingService
   *   The pricing service.
   * @param \Drupal\conreg\Service\PrintJobManager $printJobManager
   *   The print job manager.
   * @param \Drupal\conreg\Service\ConregOptions $conregOptions
   *   The ConReg options service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected EventStorage $eventStorage,
    protected PaymentStorage $paymentStorage,
    protected LanguageManagerInterface $languageManager,
    protected PricingServiceInterface $pricingService,
    protected PrintJobManager $printJobManager,
    protected ConregOptions $conregOptions,
  ) {}

  /**
   * Add a summary by check-in status to render array.
   */
  public function checkInSummary(int $eid, array &$content) {
    $descriptions = [
      0 => $this->t('Not checked in'),
      1 => $this->t('Checked in'),
    ];
    $counts = [];
    $total = 0;
    foreach ($this->memberStorage->adminMemberCheckInSummaryLoad($eid) as $entry) {
      $status = (int) trim($entry['is_checked_in']);
      $counts[$status] = (int) $entry['num'];
      $total += (int) $entry['num'];
    }

    $content['check_in_summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['conreg-checkin-summary']],
    ];

    $content['check_in_summary']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('Status'),
      '#attributes' => ['class' => ['conreg-checkin-summary__label']],
    ];

    foreach ($descriptions as $status => $label) {
      $content['check_in_summary']['status_' . $status] = $this->buildCheckInSummaryItem($label, $counts[$status] ?? 0);
    }
    $content['check_in_summary']['total'] = $this->buildCheckInSummaryItem($this->t('Total'), $total);

    return $content;
  }

  /**
   * Builds one "Label: <count>" item for the check-in summary bar.
   */
  protected function buildCheckInSummaryItem($label, int $count): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['conreg-checkin-summary__item']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label . ': ',
      ],
      'count' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $count,
        '#attributes' => ['class' => ['conreg-checkin-summary__count']],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_admin_checkin_members';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $eid = 1, $lead_mid = 0) {
    // Store Event ID in form state.
    $form_state->set('eid', $eid);
    $event = $this->eventStorage->load(['eid' => $eid]);

    // Get any existing form values for use in AJAX validation.
    $form_values = $form_state->getValues();

    $config = $this->config('conreg.settings.' . $eid);
    $labelPrintingEnabled = $config->get('checkin.label_printing_enabled') ?? FALSE;
    $types = $this->conregOptions->memberTypes($eid);
    $badgeTypes = $this->conregOptions->badgeTypes($eid);
    $days = $this->conregOptions->days($eid);

    // If lead_mid passed in, form is returning from credit cart payment. Set up
    // for check in of paid member(s).
    if ($lead_mid) {
      $result = $this->memberStorage->loadAll([
        'eid' => $eid,
        'lead_mid' => $lead_mid,
        'is_paid' => 1,
        'is_deleted' => 0,
      ]);
      $toPay = [];
      foreach ($result as $member) {
        $toPay[] = $member['mid'];
      }
      $form_state->set("action", 'checkIn');
      $form_state->set("toPay", $toPay);
    }

    // If action set, display either payment or check-in subpage.
    $action = $form_state->get("action");
    if (isset($action) && !empty($action)) {
      switch ($action) {
        case "payCash":
          $toPay = $form_state->get("toPay");
          return $this->buildCashForm($toPay, $config);

        case "checkIn":
          $toPay = $form_state->get("toPay");
          return $this->buildConfirmForm($eid, $toPay, $form_state->get("printerDisplayName"));
      }
    }

    // Falls back to a ?search= query parameter on a fresh (non-AJAX) page
    // load - UndoCheckInForm's redirect back here carries the search that
    // was active before "Undo check-in" was clicked, so confirming it
    // doesn't lose the results the user was looking at. Ignored once the
    // form has real submitted values (a search AJAX request doesn't touch
    // the URL, so $form_values['search'] and the query parameter never
    // both apply at once).
    $search = trim($form_values['search'] ?? $this->getRequest()->query->get('search', ''));

    $form = [
      '#title' => $this->t('@event_name Member Checkin', ['@event_name' => $event['event_name']]),
      '#attached' => [
        'library' => [
          'conreg/conreg_form',
          'conreg/conreg_select_all',
          'conreg/conreg_selectable_row',
          // Powers the "Badge name" action link's modal dialog (opened via
          // its use-ajax/data-dialog-type attributes - no custom JS).
          'core/drupal.dialog.ajax',
        ],
      ],
      '#prefix' => '<div id="memberForm">',
      '#suffix' => '</div>',
    ];

    $this->checkInSummary($eid, $form);

    $headers = [
      'is_checked_in' => $this->t('Check-in'),
      'badge_no' => [
        'data' => $this->t('Member no'),
        'field' => 'm.member_no',
        'sort' => 'asc',
      ],
      'first_name' => [
        'data' => $this->t('First name'),
        'field' => 'm.first_name',
      ],
      'last_name' => [
        'data' => $this->t('Last name'),
        'field' => 'm.last_name',
      ],
      'email' => [
        'data' => $this->t('Email'),
        'field' => 'm.email',
      ],
      'badge_name' => [
        'data' => $this->t('Badge name'),
        'field' => 'm.badge_name',
      ],
      'registered_by' => ['data' => $this->t('Registered By')],
      'member_type' => ['data' => $this->t('Member type')],
      'days' => ['data' => $this->t('Days')],
      'badge_type' => ['data' => $this->t('Badge type')],
      'comment' => ['data' => $this->t('Comment')],
      'is_paid' => $this->t('Paid'),
      'action' => $this->t('Action'),
    ];

    $form['search_wrapper'] = [
      '#type' => 'container',
      // 'conreg-checkin-actions' is this page's shared "row of actions"
      // flex layout, also used by $form['checkin_actions'] below -
      // 'conreg-ajax-search' is the generic pairing conreg.js's
      // triggerAjaxButtonOnEnter() looks for, kept separate so it isn't
      // tied to this page's specific layout class.
      '#attributes' => ['class' => ['conreg-checkin-actions', 'conreg-ajax-search']],
    ];

    $form['search_wrapper']['search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom search term'),
      '#default_value' => $search,
      '#attributes' => ['class' => ['conreg-ajax-search-field']],
    ];

    $form['search_wrapper']['search_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Search'),
      // A class only, no 'id' here (deliberately - it was "searchBtn"
      // before this fix). #ajax below stores its settings keyed by this
      // element's auto-generated #id and expects the rendered HTML id to
      // match; overriding just #attributes['id'] changes what's rendered
      // without touching #id, so the two go out of sync and Drupal's AJAX
      // JS can never find the element to bind to - it then silently does
      // nothing, leaving the click to fall through to a plain native form
      // submission (a full page reload). Letting Drupal assign the id
      // keeps both in sync; the class is just a stable hook for our own
      // CSS/JS.
      '#attributes' => ['class' => ['conreg-ajax-search-button']],
      '#validate' => [],
      '#submit' => ['::search'],
      // Without this, Drupal renders the button as type="submit" (the
      // element's own default - see Button::getInfo()), so pressing it
      // submits and reloads the page like a normal form submission before
      // #ajax below gets a chance to intercept it. Setting this to FALSE
      // renders type="button" instead, so #ajax is the only thing that
      // happens on click.
      '#submit_button' => FALSE,
      '#ajax' => [
        'wrapper' => 'memberForm',
        'callback' => [$this, 'updateDisplayCallback'],
      ],
    ];

    $form['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-checkin', TableRole::ListTable),
      '#empty' => $this->t('No entries available.'),
      '#sticky' => TRUE,
    ];

    // Only check database if search filled in.
    if (!empty($search)) {
      $entries = $this->memberStorage->adminMemberCheckInListLoad($eid, $search);

      foreach ($entries as $entry) {
        $mid = $entry['mid'];
        // Sanitize each entry.
        $is_paid = $entry['is_paid'];
        $row = [];
        if ($entry['is_checked_in']) {
          $row['is_checked_in'] = [
            '#markup' => $this->t('Checked in'),
            '#wrapper_attributes' => ['class' => ['conreg-checkin-status-cell']],
          ];
        }
        else {
          $row['is_checked_in'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Select'),
            '#title_display' => 'invisible',
            '#default_value' => $entry['is_checked_in'],
            '#attributes' => ['class' => ['checkbox-selectable', 'conreg-checkin-checkbox']],
            '#wrapper_attributes' => ['class' => ['conreg-checkin-status-cell']],
          ];
        }
        $row['badge_no'] = [
          '#markup' => Html::escape($this->showBadgeNumber($entry, $config)),
        ];
        $row['first_name'] = [
          '#markup' => Html::escape($entry['first_name']),
        ];
        $row['last_name'] = [
          '#markup' => Html::escape($entry['last_name']),
        ];
        $row['email'] = [
          '#markup' => Html::escape($entry['email']),
        ];
        $row['badge_name'] = [
          '#markup' => Html::escape($entry['badge_name']),
          '#wrapper_attributes' => ['id' => 'conreg-badge-name-cell-' . $mid],
        ];
        $row['registered_by'] = [
          '#markup' => Html::escape($entry['registered_by']),
        ];
        $memberType = trim($entry['member_type']);
        $row['member_type'] = [
          '#markup' => Html::escape($types->types[$memberType]->name ?? $memberType),
        ];
        if (!empty($entry['days'])) {
          $dayDescriptions = [];
          foreach (explode('|', $entry['days']) as $day) {
            $dayDescriptions[] = $days[$day] ?? $day;
          }
          $memberDays = implode(', ', $dayDescriptions);
        }
        else {
          $memberDays = '';
        }
        $row['days'] = [
          '#markup' => Html::escape($memberDays),
        ];
        $badgeType = trim($entry['badge_type']);
        $row['badge_type'] = [
          '#markup' => Html::escape($badgeTypes[$badgeType] ?? $badgeType),
        ];
        $row['comment'] = [
          '#markup' => Html::escape(trim(substr($entry['comment'], 0, 20))),
        ];
        $row['is_paid'] = [
          '#markup' => $is_paid ? $this->t('Yes') : $this->t('No'),
        ];

        if ($entry['is_checked_in']) {
          // Both actions here are supervisor-only, but on separate
          // permissions: "Undo check-in" reverts an already-confirmed
          // check-in (mutates registration/payment state), while
          // "Reprint label" just queues another badge label print (e.g.
          // for a lost badge) rather than the one-per-check-in the main
          // form otherwise enforces (consumes label stock, no data
          // change) - a site may want to grant one without the other.
          $links = [];
          if ($this->currentUser()->hasPermission('undo convention member check-in')) {
            $links['undo_check_in'] = [
              'title' => $this->t('Undo check-in'),
              // Carries the active search along so UndoCheckInForm's
              // post-confirm redirect can restore it (see $search
              // above) instead of landing back on an empty search box.
              'url' => Url::fromRoute('conreg_admin_checkin_undo', ['eid' => $eid, 'mid' => $mid], ['query' => ['search' => $search]]),
              'attributes' => [
                'class' => ['use-ajax'],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => Json::encode(['width' => 400]),
              ],
            ];
          }
          if ($labelPrintingEnabled && $this->currentUser()->hasPermission('reprint convention member badge label')) {
            $links['reprint_label'] = [
              'title' => $this->t('Reprint label'),
              // Carries the active search along so ReprintLabelForm's
              // post-confirm redirect can restore it, same as
              // undo_check_in above.
              'url' => Url::fromRoute('conreg_admin_checkin_reprint_label', ['eid' => $eid, 'mid' => $mid], ['query' => ['search' => $search]]),
              'attributes' => [
                'class' => ['use-ajax'],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => Json::encode(['width' => 500]),
              ],
            ];
          }
          $row['action'] = $links ? ['#type' => 'dropbutton', '#links' => $links] : [];
        }
        else {
          // Plain links, not form elements - core/drupal.dialog.ajax
          // (attached below) opens each in a modal automatically via the
          // use-ajax/data-dialog-type attributes, with no custom JS and
          // none of the "#ajax on a form element repeated once per row"
          // problems a hand-built per-row control would run into (see
          // CheckInBadgeNameForm and CheckInLabelPreviewController,
          // which do the actual work behind each option).
          $row['action'] = [
            '#type' => 'dropbutton',
            '#links' => [
              'badge_name' => [
                'title' => $this->t('Badge name'),
                'url' => Url::fromRoute('conreg_admin_checkin_badge_name', ['eid' => $eid, 'mid' => $mid]),
                'attributes' => [
                  'class' => ['use-ajax'],
                  'data-dialog-type' => 'modal',
                  'data-dialog-options' => Json::encode(['width' => 400]),
                ],
              ],
              'preview_label' => [
                'title' => $this->t('Preview label'),
                'url' => Url::fromRoute('conreg_admin_checkin_label_preview', ['eid' => $eid, 'mid' => $mid]),
                'attributes' => [
                  'class' => ['use-ajax'],
                  'data-dialog-type' => 'modal',
                  'data-dialog-options' => Json::encode(['width' => 500]),
                ],
              ],
            ],
          ];
        }

        $form['table'][$mid] = $row;

        if (!$entry['is_checked_in']) {
          // Generic class - conreg_selectable_row.js's click-anywhere-on-
          // the-row-to-toggle-the-checkbox behavior isn't check-in-specific,
          // so any table's rows can opt into it the same way.
          $form['table'][$mid]['#attributes']['class'][] = 'conreg-table-row--selectable';
        }
      }
    }

    $form['select_all_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['conreg-checkin-select-all']],
    ];

    $form['select_all_wrapper']['select_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Select all'),
      '#attributes' => ['class' => ['select-all']],
    ];

    $form['checkin_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['conreg-checkin-actions']],
    ];

    $form['checkin_actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check-in selected'),
      '#submit' => [[$this, 'checkInSubmit']],
      '#attributes' => ['id' => "submitBtn"],
    ];

    if ($labelPrintingEnabled) {
      $printers = $this->printJobManager->getPrintersForEvent($eid);
      $printerOptions = [];
      foreach ($printers as $printer) {
        $printerOptions[$printer->get('machine_name')->value] = $printer->label();
      }

      $rememberedPrinter = $this->getRememberedPrinter($eid);

      $form['checkin_actions']['printer'] = [
        '#type' => 'select',
        '#title' => $this->t('Printer'),
        '#options' => $printerOptions,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => isset($printerOptions[$rememberedPrinter]) ? $rememberedPrinter : NULL,
      ];

      $form['checkin_actions']['submit_print'] = [
        '#type' => 'submit',
        '#value' => $this->t('Check-in and print labels'),
        '#validate' => [[$this, 'validatePrinterSelected']],
        '#submit' => [[$this, 'checkInAndPrintSubmit']],
        '#disabled' => !$printerOptions,
      ];

      if (!$printerOptions) {
        $form['no_printers'] = [
          '#type' => 'markup',
          '#markup' => $this->t('No printers are configured for this event, so badge labels cannot be printed.'),
          '#prefix' => '<div class="messages messages--warning">',
          '#suffix' => '</div>',
        ];
      }
    }

    $form['unpaid_divider'] = [
      '#type' => 'html_tag',
      '#tag' => 'hr',
      '#attributes' => ['class' => ['conreg-checkin-divider']],
    ];

    $form['unpaid_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Unpaid members and walk-ins'),
    ];

    $headers = [
      'first_name' => [
        'data' => $this->t('First name'),
        'field' => 'm.first_name',
      ],
      'last_name' => [
        'data' => $this->t('Last name'),
        'field' => 'm.last_name',
      ],
      'email' => [
        'data' => $this->t('Email'),
        'field' => 'm.email',
      ],
      'badge_name' => [
        'data' => $this->t('Badge name'),
        'field' => 'm.badge_name',
      ],
      'member_type' => ['data' => $this->t('Member type')],
      'days' => ['data' => $this->t('Days')],
      'price' => ['data' => $this->t('Price'), 'field' => 'm.member_total'],
      'select' => $this->t('Select'),
      /*t('Action'),*/
    ];

    $form['unpaid'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-unpaid', TableRole::ListTable),
      '#empty' => $this->t('No entries available.'),
      '#sticky' => TRUE,
    ];

    $entries = $this->memberStorage->adminMemberUnpaidListLoad($eid);

    foreach ($entries as $entry) {
      $mid = $entry['mid'];
      // Sanitize each entry.
      $is_paid = $entry['is_paid'];
      $row = [];
      $row['first_name'] = [
        '#markup' => Html::escape($entry['first_name']),
      ];
      $row['last_name'] = [
        '#markup' => Html::escape($entry['last_name']),
      ];
      $row['email'] = [
        '#markup' => Html::escape($entry['email']),
      ];
      $row['badge_name'] = [
        '#markup' => Html::escape($entry['badge_name']),
      ];
      $memberType = trim($entry['member_type']);
      $row['member_type'] = [
        '#markup' => Html::escape($types->types[$memberType]->name ?? $memberType),
      ];
      if (!empty($entry['days'])) {
        $dayDescriptions = [];
        foreach (explode('|', $entry['days']) as $day) {
          $dayDescriptions[] = $days[$day] ?? $day;
        }
        $memberDays = implode(', ', $dayDescriptions);
      }
      else {
        $memberDays = '';
      }
      $row['days'] = [
        '#markup' => Html::escape($memberDays),
      ];
      $row['price'] = [
        '#markup' => Html::escape($entry['member_total']),
      ];
      $row["is_selected"] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select'),
        '#default_value' => 0,
      ];

      $form['unpaid'][$mid] = $row;
    }

    // Extra table row with blank form for new member.
    $row = [];
    $row["first_name"] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#title_display' => 'invisible',
      '#size' => 15,
    ];
    $row["last_name"] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#title_display' => 'invisible',
      '#size' => 15,
    ];
    $row["email"] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email'),
      '#title_display' => 'invisible',
      '#size' => 15,
    ];
    $row["badge_name"] = [
      '#type' => 'textfield',
      '#title' => $this->t('Badge Name'),
      '#title_display' => 'invisible',
      '#size' => 15,
    ];
    $row['memberType'] = [
      '#type' => 'select',
      '#title' => $this->t('Member Type'),
      '#options' => $types->publicNames,
      '#title_display' => 'invisible',
    ];
    $row['days'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Days'),
      '#options' => $days,
      '#title_display' => 'invisible',
    ];
    $row['price'] = [];
    $row['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add'),
      '#submit' => [[$this, 'addMember']],
    ];
    $form['unpaid']['add'] = $row;

    $form['cash'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pay Cash'),
      '#submit' => [[$this, 'payCash']],
    ];

    $form['card'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pay Credit Card'),
      '#submit' => [[$this, 'payCard']],
    ];

    return $form;
  }

  /**
   * Set up markup fields to display cash payment.
   */
  public function buildCashForm(array $toPay, ImmutableConfig $config) {
    $symbol = $config->get('payments.symbol');
    $form = [];
    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Please confirm cash received from:'),
      '#prefix' => '<div><h3>',
      '#suffix' => '</h3></div>',
    ];
    $total_price = 0;
    foreach ($toPay as $mid) {
      if ($member = $this->memberStorage->load(['mid' => $mid])) {
        $form['member' . $mid] = [
          '#type' => 'markup',
          '#markup' => $this->t('Member @first @last to pay @symbol@total',
          [
            '@first' => $member['first_name'],
            '@last' => $member['last_name'],
            '@symbol' => $symbol,
            '@total' => $member['member_total'],
          ]),
          '#prefix' => '<div>',
          '#suffix' => '</div>',
        ];
        $total_price += $member['member_total'];
      }
    }
    $form['payment_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Payment method'),
      '#options' => $this->conregOptions->paymentMethod(),
      '#default_value' => "Cash",
      '#required' => TRUE,
    ];
    $form['payment_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment reference'),
    ];
    $form['total'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Total to pay @symbol@total', [
        '@symbol' => $symbol,
        '@total' => $total_price,
      ]),
      '#prefix' => '<div><h4>',
      '#suffix' => '</h4></div>',
    ];
    $form['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm Cash Payment'),
      '#submit' => [[$this, 'confirmPayCash']],
    ];
    $form['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => [[$this, 'cancelAction']],
    ];
    return $form;
  }

  /**
   * Set up markup fields to display check-in confirm.
   */
  public function buildConfirmForm(int $eid, array $toPay, ?string $printerDisplayName = NULL) {
    $config = $this->config('conreg.settings.' . $eid);
    $form = [];
    // The "Check-in selected"/"Check-in and print labels" buttons that
    // reach this have no #ajax, so this becomes a genuine full-page
    // rebuild (not a partial AJAX replace of #memberForm) - buildForm()
    // returns straight from this method without ever reaching its own
    // '#attached' declaration below, so the .conreg-checkin-confirm-list
    // styling (and any other conreg.css rule) would otherwise never load
    // for this step.
    $form['#attached']['library'][] = 'conreg/conreg_form';
    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Please confirm badges for:'),
      '#prefix' => '<div><h3>',
      '#suffix' => '</h3></div>',
    ];
    if ($printerDisplayName) {
      $form['printer_notice'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Badge labels will be printed on printer %printer.', ['%printer' => $printerDisplayName]),
        '#prefix' => '<div>',
        '#suffix' => '</div>',
      ];
    }
    $maxMemberNo = $this->memberStorage->loadMaxMemberNo($eid);
    $items = [];
    foreach ($toPay as $mid) {
      if ($member = $this->memberStorage->load(['mid' => $mid])) {
        $update = ['mid' => $mid];
        if (!(isset($member['is_confirmed']) && $member['is_approved'])) {
          $update['is_approved'] = 1;
        }
        if (isset($member['member_no']) && $member['member_no']) {
          $member_no = $member['member_no'];
        }
        else {
          $member_no = ++$maxMemberNo;
          // Add member number to loaded member entry.
          $member['member_no'] = $member_no;
          // Add to update record so it will be saved.
          $update['member_no'] = $member_no;
        }
        $this->memberStorage->update($update);

        $item = [
          'text' => [
            '#markup' => $this->t('Badge number <strong>@memberno</strong> for <strong>@first @last</strong>',
            [
              '@memberno' => $this->showBadgeNumber($member, $config),
              '@first' => $member['first_name'],
              '@last' => $member['last_name'],
            ]),
          ],
        ];

        // Only when printing (not a plain check-in) - shows exactly what
        // will print, using the same rendering path the "Preview
        // label"/"Reprint label" modals use (renderPreviewImageForMember()
        // rather than renderPreviewImage($mid) - $member is already
        // loaded and updated above, so there's no need to reload and
        // re-render it from scratch for every member in this loop).
        if ($printerDisplayName) {
          $imageBase64 = $this->printJobManager->renderPreviewImageForMember($member);
          if ($imageBase64 !== NULL) {
            $item['preview'] = [
              '#type' => 'html_tag',
              '#tag' => 'img',
              '#attributes' => [
                'src' => 'data:image/png;base64,' . $imageBase64,
                'alt' => $this->t('Label preview'),
                'class' => ['conreg-label-preview-image', 'conreg-checkin-confirm-preview'],
              ],
            ];
          }
        }

        $items[] = $item;
      }
    }

    $form['members'] = [
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#attributes' => ['class' => ['conreg-checkin-confirm-list']],
      '#items' => $items,
    ];
    $form['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm Check-In'),
      '#submit' => [[$this, 'confirmCheckInSubmit']],
    ];
    $form['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => [[$this, 'cancelAction']],
    ];
    return $form;
  }

  /**
   * Callback function for "display" drop down.
   */
  public function updateDisplayCallback(array $form, FormStateInterface $form_state) {
    // Return new form.
    return $form;
  }

  /**
   * Callback for search.
   */
  public function search(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }

  /**
   * Callback to add a member.
   */
  public function addMember(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $config = ConregConfig::getConfig($eid);
    $types = $this->conregOptions->memberTypes($eid);
    $form_values = $form_state->getValues();
    $language = $this->languageManager->getDefaultLanguage()->getId();
    // Assign random key for payment URL.
    $rand_key = mt_rand();
    if (!empty($form_values['unpaid']['add']['badge_name'])) {
      $badge_name = trim($form_values['unpaid']['add']['badge_name']);
    }
    else {
      $badge_name = trim($form_values['unpaid']['add']['first_name'] . ' ' . $form_values['unpaid']['add']['last_name']);
    }
    // Work out price.
    $pricingContext = PricingContext::fromFormValues($eid, $config, $types->types, $form_values);
    $subject = PricingSubject::fromCheckInFormValues($form_values);
    $pricingResult = $this->pricingService->priceRegistration($pricingContext, [1 => $subject]);
    $memberResult = $pricingResult->memberResults[1];

    // Save the submitted entry.
    $entry = [
      'eid' => $eid,
      'lead_mid' => 0,
      'random_key' => $rand_key,
      'member_type' => $memberResult->memberType,
      'days' => $memberResult->days,
      'first_name' => $form_values['unpaid']['add']['first_name'],
      'last_name' => $form_values['unpaid']['add']['last_name'],
      'badge_name' => $badge_name,
      'badge_type' => 'A',
      'display' => $config->get('checkin.display'),
      'communication_method' => $config->get('checkin.communication_method'),
      'email' => $form_values['unpaid']['add']['email'],
      'member_price' => $memberResult->basePrice(),
      'member_total' => $memberResult->price(),
      'add_on_price' => $memberResult->addOnPrice(),
      'payment_amount' => $pricingResult->totalPrice(),
      'join_date' => time(),
      'update_date' => time(),
      'language' => $language,
    ];
    // Insert to database table.
    $return = $this->memberStorage->insert($entry);

    if ($return) {
      // Update member with own member ID as lead member ID.
      $update = ['mid' => $return, 'lead_mid' => $return];
      $return = $this->memberStorage->update($update);
      // Clear form fields.
      $form_state->setUserInput([]);
    }
    $form_state->setRebuild();
  }

  /**
   * Returns the submitted check-in table rows, keyed by member ID.
   *
   * Normally an array built from $form['table']'s per-member checkboxes,
   * but a browser can resubmit a stale POST body (e.g. via the "confirm
   * form resubmission" refresh prompt) that predates the current form
   * structure, in which case this key may be missing or the wrong type
   * entirely. Falling back to an empty array here treats that the same as
   * "no members selected" instead of raising a foreach() warning.
   */
  protected function getSubmittedTableRows(array $form_values): array {
    return is_array($form_values['table'] ?? NULL) ? $form_values['table'] : [];
  }

  /**
   * Callback for submit button.
   */
  public function checkInSubmit(array &$form, FormStateInterface $form_state) {
    $form_values = $form_state->getValues();

    $toPay = [];
    foreach ($this->getSubmittedTableRows($form_values) as $mid => $member) {
      if (isset($member["is_checked_in"]) && $member["is_checked_in"]) {
        $toPay[] = $mid;
      }
    }
    if (count($toPay)) {
      $form_state->set("action", "checkIn");
      $form_state->set("toPay", $toPay);
      // A plain check-in, not check-in-and-print - clears any printer
      // left over from a previous "Check-in and print labels" attempt in
      // the same form-rebuild lifecycle (e.g. selected, then Cancelled,
      // then a different set of members checked in without printing),
      // so buildConfirmForm() doesn't show a stale label preview.
      $form_state->set("printerMachineName", NULL);
      $form_state->set("printerDisplayName", NULL);
    }
    $form_state->setRebuild();
  }

  /**
   * Validate a printer was selected before checking in and printing.
   */
  public function validatePrinterSelected(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getValue('printer')) {
      $form_state->setErrorByName('printer', $this->t('Select a printer before checking in and printing labels.'));
    }
  }

  /**
   * Callback for check-in-and-print submit button.
   */
  public function checkInAndPrintSubmit(array &$form, FormStateInterface $form_state) {
    $form_values = $form_state->getValues();

    $toPay = [];
    foreach ($this->getSubmittedTableRows($form_values) as $mid => $member) {
      if (isset($member["is_checked_in"]) && $member["is_checked_in"]) {
        $toPay[] = $mid;
      }
    }
    if (count($toPay)) {
      $form_state->set("action", "checkIn");
      $form_state->set("toPay", $toPay);
      $printerMachineName = $form_values['printer'];
      $form_state->set("printerMachineName", $printerMachineName);
      $form_state->set("printerDisplayName", $form['checkin_actions']['printer']['#options'][$printerMachineName] ?? $printerMachineName);
      $this->rememberPrinter((int) $form_state->get('eid'), $printerMachineName);
    }
    $form_state->setRebuild();
  }

  /**
   * Callback for pay cash button.
   */
  public function payCash(array &$form, FormStateInterface $form_state) {
    $form_values = $form_state->getValues();

    $toPay = [];
    foreach ($form_values["unpaid"] as $mid => $member) {
      if (isset($member["is_selected"]) && $member["is_selected"]) {
        $toPay[] = $mid;
      }
    }
    // No need to proceed unless members have been selected.
    if (count($toPay)) {
      $form_state->set("action", "payCash");
      $form_state->set("toPay", $toPay);
    }
    $form_state->setRebuild();
  }

  /**
   * Confirm button callback.
   */
  public function confirmPayCash(array &$form, FormStateInterface $form_state) {
    $form_values = $form_state->getValues();

    $payment_amount = 0;
    $lead_mid = 0;
    $toPay = $form_state->get("toPay");
    // Loop through selected members to get lead and total price.
    foreach ($toPay as $mid) {
      if ($member = $this->memberStorage->load(['mid' => $mid])) {
        // Make first member lead member.
        if ($lead_mid == 0) {
          $lead_mid = $mid;
        }
        $payment_amount += $member['member_total'];
      }
    }
    // Loop again to update members.
    foreach ($toPay as $mid) {
      $update = [
        'mid' => $mid,
        'lead_mid' => $lead_mid,
        'payment_amount' => $payment_amount,
        'payment_method' => $form_values['payment_method'],
        'payment_id' => $form_values['payment_id'],
        'is_paid' => 1,
      ];
      $this->memberStorage->update($update);
    }
    $form_state->set('action', 'checkIn');
    $form_state->setRebuild();
  }

  /**
   * Callback for check in button.
   */
  public function confirmCheckInSubmit(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get("eid");
    $config = $this->config('conreg.settings.' . $eid);
    $toPay = $form_state->get("toPay");
    $uid = $this->currentUser()->id();
    $printerMachineName = $form_state->get("printerMachineName");
    $printerDisplayName = $form_state->get("printerDisplayName");
    // Loop through members and mark checked in.
    foreach ($toPay as $mid) {
      $update = [
        'mid' => $mid,
        'is_checked_in' => 1,
        'check_in_date' => time(),
        'check_in_by' => $uid,
      ];
      $this->memberStorage->update($update);
      if ($member = $this->memberStorage->load(['mid' => $mid])) {
        $this->messenger()->addMessage($this->t("Member %badge_no - %badge_name checked in.", [
          '%badge_no' => $this->showBadgeNumber($member, $config),
          '%badge_name' => $member['badge_name'],
        ]));
        if ($printerMachineName) {
          try {
            $this->printJobManager->createJob($mid, $printerMachineName);
            $this->messenger()->addMessage($this->t("Print job for %badge_name queued on %printer.", [
              '%badge_name' => $member['badge_name'],
              '%printer' => $printerDisplayName,
            ]));
          }
          catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($this->t("Could not queue a print job for %badge_name: @message", [
              '%badge_name' => $member['badge_name'],
              '@message' => $e->getMessage(),
            ]));
          }
        }
      }
    }
    // Form may have checked in member in URL. Redirect to clear.
    $form_state->setRedirect('conreg_admin_checkin', ['eid' => $eid]);
  }

  /**
   * Callback function for pay by card.
   */
  public function payCard(array &$form, FormStateInterface $form_state) {
    $form_values = $form_state->getValues();

    // Create a payment object.
    $payment = new Payment($this->paymentStorage);

    $payment_amount = 0;
    $lead_mid = 0;
    // Loop through selected members to get lead and total price.
    foreach ($form_values["unpaid"] as $mid => $member) {
      if (isset($member["is_selected"]) && $member["is_selected"]) {
        if ($member = $this->memberStorage->load(['mid' => $mid])) {
          // Add member to payment.
          $payment->add(new PaymentLine($this->paymentStorage, $mid, 'member',
            $this->t("Member registration for @first_name @last_name",
          [
            '@first_name' => $member['first_name'],
            '@last_name' => $member['last_name'],
          ]),
            $member['member_price']));
          // Make first member lead member.
          if ($lead_mid == 0) {
            $lead_mid = $mid;
          }
          $payment_amount += $member['member_total'];
        }
      }
    }
    // Loop again to update members.
    foreach ($form_values["unpaid"] as $mid => $member) {
      if (isset($member["is_selected"]) && $member["is_selected"]) {
        $update = [
          'mid' => $mid,
          'lead_mid' => $lead_mid,
          'payment_amount' => $payment_amount,
        ];
        $this->memberStorage->update($update);
      }
    }
    if ($lead_mid) {
      // Save the payment.
      $payid = $payment->save();

      // Redirect to payment form.
      $form_state->setRedirect('conreg_checkin_checkout',
        ['payid' => $payid, 'key' => $payment->randomKey]
      );
    }
  }

  /**
   * Callback for cancel button.
   */
  public function cancelAction(array &$form, FormStateInterface $form_state) {
    $form_state->set('action', '');
    // Clears any printer chosen for a since-cancelled "Check In and Print
    // Labels" attempt, so it can't leak into a later plain check-in - see
    // checkInSubmit()'s same reset.
    $form_state->set('printerMachineName', NULL);
    $form_state->set('printerDisplayName', NULL);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $eid = $form_state->get('eid');
    $form_values = $form_state->getValues();
    $saved_members = $this->memberStorage->loadAllMemberNos($eid);
    $uid = $this->currentUser()->id();
    foreach ($this->getSubmittedTableRows($form_values) as $mid => $member) {
      if ($member["is_checked_in"] != $saved_members[$mid]["is_checked_in"]) {
        if ($member["is_checked_in"]) {
          $entry = [
            'mid' => $mid,
            'is_checked_in' => $member["is_checked_in"],
            'check_in_date' => time(),
            'check_in_by' => $uid,
          ];
        }
        else {
          $entry = ['mid' => $mid, 'is_checked_in' => $member["is_checked_in"]];
        }
        $this->memberStorage->update($entry);
      }
    }
    Cache::invalidateTags(['conreg-member-list']);
  }

}
