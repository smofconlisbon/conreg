<?php

namespace Drupal\conreg\Controller;

use Drupal\conreg\ConregConfig;
use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Trait\ShowBadgeNumberTrait;
use Drupal\conreg\ConregTable;
use Drupal\conreg\TableRole;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateHelper;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Utility\Token;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for ConReg.
 */
class ConregController extends ControllerBase {

  use ShowBadgeNumberTrait;

  /**
   * Constructor for member lookup form.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The HTTP request stack.
   * @param \Drupal\conreg\Service\EventStorage $eventStorage
   *   The event storage service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\conreg\Service\ConregOptions $conregOptions
   *   The ConReg options service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected RequestStack $requestStack,
    protected EventStorage $eventStorage,
    protected Token $token,
    protected ConregOptions $conregOptions,
  ) {}

  /**
   * Display simple thank you page.
   */
  public function registrationThanks(int $eid = 1) {
    $config = $this->config('conreg.settings.' . $eid);
    $event = $this->eventStorage->load(['eid' => $eid]);
    $drupalTokenData = [
      'event' => [
        'eid' => $eid,
        'name' => $event['event_name'],
        'email' => $config->get('confirmation.from_email'),
      ],
    ];

    $content = [
      '#title' => $config->get('thanks.title'),
    ];

    $bubbleable_metadata = new BubbleableMetadata();
    $content['message'] = [
      '#type' => 'processed_text',
      '#text' => $this->token->replace($config->get('thanks.thank_you_message'), $drupalTokenData, [], $bubbleable_metadata),
      '#format' => $config->get('thanks.thank_you_format'),
    ];
    $bubbleable_metadata->applyTo($content);

    return $content;
  }

  /**
   * Render a list of entries in the database.
   */
  public function memberList(int $eid = 1) {
    // ControllerBase::config() always returns an immutable config object;
    // its docblock (@return Config) is just stale.
    /** @var \Drupal\Core\Config\ImmutableConfig $config */
    $config = $this->config('conreg.settings.' . $eid);
    $countryOptions = $this->conregOptions->memberCountries($eid);
    $types = $this->conregOptions->badgeTypes($eid);
    $digits = $config->get('member_no_digits');

    $showMemberList = $config->get('member_listing_page.show_members') ?? TRUE;
    $showMemberNo = $config->get('member_listing_page.show_member_no') ?? TRUE;
    $showCountries = $config->get('member_listing_page.show_countries') ?? TRUE;
    $showSummary = $config->get('member_listing_page.show_summary') ?? TRUE;

    switch ($this->requestStack->getCurrentRequest()->query->get('sort') ?? '') {
      case 'desc':
        $direction = 'DESC';
        break;

      default:
        $direction = 'ASC';
        break;
    }
    switch ($this->requestStack->getCurrentRequest()->query->get('order') ?? '') {
      case 'Name':
        $order = 'name';
        break;

      case 'Country':
        $order = 'country';
        break;

      case 'Type':
        $order = 'badge_type';
        break;

      default:
        $order = 'member_no';
        break;
    }

    $content = [
      '#cache' => [
        'tags' => ['event:' . $eid . ':members'],
        'contexts' => ['url.query_args:sort', 'url.query_args:order'],
        'max-age' => Cache::PERMANENT,
      ],
    ];

    // If public member list disabled, return message.
    if (!$showMemberList) {
      $content['message'] = [
        '#markup' => $this->t("Public member list is not available."),
      ];
      return $content;
    }

    $content['message'] = [
      '#cache' => ['tags' => ['conreg-member-list'], '#max-age' => 600],
      '#markup' => $this->t("Members' public details are listed below."),
    ];

    $rows = [];
    $headers = [];
    if ($showMemberNo) {
      $headers['member_no'] = [
        'data' => $this->t('Member No'),
        'field' => 'm.member_no',
        'sort' => 'asc',
      ];
    }
    $headers['member_name'] = [
      'data' => $this->t('Name'),
      'field' => 'name',
    ];
    $headers['badge_type'] = [
      'data' => $this->t('Type'),
      'field' => 'm.badge_type',
      'class' => [RESPONSIVE_PRIORITY_LOW],
    ];
    if ($showCountries) {
      $headers['member_country'] = [
        'data' => $this->t('Country'),
        'field' => 'm.country',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ];
    }
    $total = 0;

    foreach ($this->memberStorage->adminPublicListLoad($eid) as $entry) {
      // Sanitize each entry.
      $badge_type = trim($entry['badge_type']);
      $member = [];
      if ($showMemberNo) {
        $member['member_no'] = $this->showBadgeNumber($entry, $config);
      }
      switch ($entry['display']) {
        case 'F':
          $fullname = trim(trim($entry['first_name']) . ' ' . trim($entry['last_name']));
          if ($fullname != trim($entry['badge_name'])) {
            $fullname .= ' (' . trim($entry['badge_name']) . ')';
          }
          $member['name'] = $fullname;
          break;

        case 'B':
          $member['name'] = trim($entry['badge_name']);
          break;

        case 'N':
          $member['name'] = $this->t('Name withheld');
          break;
      }
      $member['badge_type'] = trim($types[$badge_type] ?? $badge_type);
      if ($showCountries) {
        $member['country'] = trim($countryOptions[$entry['country']] ?? $entry['country']);
      }

      // Set key to field to be sorted by.
      $paddedMemberNo = sprintf("%0" . $digits . "d", $entry['member_no']);
      if ($order == 'member_no') {
        $key = $paddedMemberNo;
      }
      // Append member number to ensure uniqueness.
      else {
        $key = $member[$order] . $paddedMemberNo;
      }
      if (!empty($entry['display']) && $entry['display'] != 'N' && !empty($entry['country'])) {
        $rows[$key] = $member;
      }
    }

    // Sort array by key.
    if ($direction == 'DESC') {
      krsort($rows);
    }
    else {
      ksort($rows);
    }

    $content['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-list', TableRole::ListTable),
      '#rows' => $rows,
      '#empty' => $this->t('No entries available.'),
    ];

    // Member summary page.
    if ($showSummary) {
      $content['country_summary'] = [
        '#prefix' => '<div class="conreg-table-section conreg-table-section--country-breakdown">',
        '#suffix' => '</div>',
      ];

      $content['country_summary']['summary_heading'] = [
        '#markup' => $this->t('Country Breakdown'),
        '#prefix' => '<h2>',
        '#suffix' => '</h2>',
      ];

      $rows = [];
      $headers = [
        $this->t('Country'),
        $this->t('Number of members'),
      ];
      $total = 0;
      foreach ($this->memberStorage->adminMemberCountrySummaryLoad($eid) as $entry) {
        if (!empty($entry['country'])) {
          // Sanitize each entry.
          $entry['country'] = trim($countryOptions[$entry['country']]);
          $rows[] = $entry;
          $total += $entry['num'];
        }
      }
      // Add a footer row for the total.
      $footer = [
        [
          ['data' => $this->t('Total')],
          ['data' => $total],
        ],
      ];
      $content['country_summary']['summary'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#attributes' => ConregTable::attributes('member-list-country-summary', TableRole::Summary),
        '#rows' => $rows,
        '#footer' => $footer,
        '#empty' => $this->t('No entries available.'),
      ];
    }

    return $content;
  }

  /**
   * Add a summary by member type to render array.
   *
   * Also called from memberAdminMemberList(), which builds its own page
   * heading and doesn't want this method's - hence $showHeading.
   */
  public function memberAdminMemberListSummary(int $eid, bool $showHeading = TRUE): array {

    $element = [
      '#prefix' => '<div class="conreg-table-section conreg-table-section--member-type">',
      '#suffix' => '</div>',
    ];

    if ($showHeading) {
      $element['message_member'] = [
        '#markup' => $this->t('Summary by member type'),
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
    }

    $types = $this->conregOptions->memberTypes($eid);
    $headers = [
      'type' => $this->t('Member Type'),
      'number' => $this->t('Number of members'),
    ];
    $rows = [];
    $total = 0;
    foreach ($this->memberStorage->adminMemberSummaryLoad($eid) as $entry) {
      // Replace type code with description.
      $rows[] = [
        'type' => ['data' => isset($types->types[$entry['member_type']]) ? $types->types[$entry['member_type']]->name : $entry['member_type']],
        'number' => ['data' => $entry['num']],
      ];
      $total += $entry['num'];
    }
    // Add a row for the total.
    $footer = ConregTable::totalFooterRow(['type' => $this->t('Total'), 'number' => $total]);
    $element['summary'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-summary-by-type', TableRole::Summary),
      '#rows' => $rows,
      '#footer' => $footer,
      '#empty' => $this->t('No entries available.'),
    ];

    return $element;
  }

  /**
   * Add a summary by badge type to render array.
   */
  public function memberAdminMemberListBadgeSummary(int $eid): array {
    $element = [
      '#prefix' => '<div class="conreg-table-section conreg-table-section--badge-type">',
      '#suffix' => '</div>',
    ];

    $element['message_badge_type'] = [
      '#markup' => $this->t('Summary by badge type'),
      '#prefix' => '<h3>',
      '#suffix' => '</h3>',
    ];

    $types = $this->conregOptions->badgeTypes($eid);
    $headers = [
      'type' => $this->t('Badge Type'),
      'number' => $this->t('Number of members'),
    ];
    $rows = [];
    $total = 0;
    foreach ($this->memberStorage->adminMemberBadgeSummaryLoad($eid) as $entry) {
      // Replace type code with description.
      $rows[] = [
        'type' => ['data' => $types[trim($entry['badge_type'])] ?? $entry['badge_type']],
        'number' => ['data' => $entry['num']],
      ];
      $total += $entry['num'];
    }
    // Add a row for the total.
    $footer = ConregTable::totalFooterRow(['type' => $this->t('Total'), 'number' => $total]);
    $element['badge_summary'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-summary-by-badge', TableRole::Summary),
      '#rows' => $rows,
      '#footer' => $footer,
      '#empty' => $this->t('No entries available.'),
    ];

    return $element;
  }

  /**
   * Add a summary by day to render array.
   */
  public function memberAdminMemberListDaysSummary(int $eid): array {
    $element = [
      '#prefix' => '<div class="conreg-table-section conreg-table-section--days">',
      '#suffix' => '</div>',
    ];

    $element['message_days'] = [
      '#markup' => $this->t('Summary by day'),
      '#prefix' => '<h3>',
      '#suffix' => '</h3>',
    ];

    $days = $this->conregOptions->days($eid);

    $dayTotals = [];
    foreach ($days as $key => $val) {
      $dayTotals[$key] = 0;
    }
    $total = 0;
    foreach ($this->memberStorage->adminMemberDaysSummaryLoad($eid) as $entry) {
      // Sanitize each entry.
      foreach (explode('|', $entry['days']) as $day) {
        $dayTotals[$day] += $entry['num'];
      }
      $total += $entry['num'];
    }

    $headers = [
      $this->t('Days'),
      $this->t('Number of members'),
    ];
    $rows = [];
    foreach ($dayTotals as $key => $val) {
      // Sanitize each entry.
      $rows[] = [
        ['data' => $days[$key] ?? $key],
        ['data' => $val],
      ];
    }
    // Add a row for the total.
    $footer = ConregTable::totalFooterRow([$this->t('Total'), $total]);
    $element['days_summary'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-summary-by-day', TableRole::Summary),
      '#rows' => $rows,
      '#footer' => $footer,
      '#empty' => $this->t('No entries available.'),
    ];

    return $element;
  }

  /**
   * Add a summary by payment method to render array.
   */
  public function memberAdminMemberListPaymentMethodSummary(int $eid): array {
    $element = [
      '#prefix' => '<div class="conreg-table-section conreg-table-section--payment-method">',
      '#suffix' => '</div>',
    ];

    $element['message_payment_method'] = [
      '#markup' => $this->t('Summary by payment method'),
      '#prefix' => '<h3>',
      '#suffix' => '</h3>',
    ];

    $headers = [
      $this->t('Payment Method'),
      $this->t('Number of members'),
    ];
    $rows = [];
    $total = 0;
    foreach ($this->memberStorage->adminMemberPaymentMethodSummaryLoad($eid) as $entry) {
      // Sanitize each entry.
      $rows[] = [
        ['data' => $entry['payment_method']],
        ['data' => $entry['num']],
      ];
      $total += $entry['num'];
    }
    // Add a row for the total.
    $footer = ConregTable::totalFooterRow([$this->t('Total'), $total]);
    $element['payment_method_summary'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-summary-by-payment-method', TableRole::Summary),
      '#rows' => $rows,
      '#footer' => $footer,
      '#empty' => $this->t('No entries available.'),
    ];

    return $element;
  }

  /**
   * Add a summary by amount paid to render array.
   */
  public function memberAdminMemberListAmountPaidSummary(int $eid): array {
    $element = [
      '#prefix' => '<div class="conreg-table-section conreg-table-section--amount-paid">',
      '#suffix' => '</div>',
    ];

    $element['message_amount_paid'] = [
      '#markup' => $this->t('Summary by amount paid'),
      '#prefix' => '<h3>',
      '#suffix' => '</h3>',
    ];

    $headers = [
      $this->t('Amount Paid'),
      $this->t('Number of members'),
      $this->t('Total Paid'),
    ];
    $rows = [];
    $total = 0;
    $total_amount = 0;
    foreach ($this->memberStorage->adminMemberAmountPaidSummaryLoad($eid) as $entry) {
      // Calculate total received at that rate.
      $total_paid = $entry['member_price'] * $entry['num'];
      // Sanitize each entry.
      $rows[] = [
        ['data' => $entry['member_price']],
        ['data' => $entry['num']],
        ['data' => number_format($total_paid, 2)],
      ];
      $total += $entry['num'];
      $total_amount += $total_paid;
    }
    // Add a row for the total.
    $footer = ConregTable::totalFooterRow([$this->t('Total'), $total, number_format($total_amount, 2)]);
    $element['amount_paid_summary'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-summary-by-amount-paid', TableRole::Summary),
      '#rows' => $rows,
      '#footer' => $footer,
      '#empty' => $this->t('No entries available.'),
    ];

    return $element;
  }

  /**
   * Add a summary by member type and amount paid to render array.
   */
  public function memberAdminMemberListAmountPaidByTypeSummary(int $eid): array {
    $element = [
      '#prefix' => '<div class="conreg-table-section conreg-table-section--amount-paid-by-type">',
      '#suffix' => '</div>',
    ];

    $element['message_type_amount_paid'] = [
      '#markup' => $this->t('Summary by member type and amount paid'),
      '#prefix' => '<h3>',
      '#suffix' => '</h3>',
    ];

    $types = $this->conregOptions->memberTypes($eid);
    $headers = [
      $this->t('Member Type'),
      $this->t('Amount Paid'),
      $this->t('Number of members'),
      $this->t('Total Paid'),
    ];
    $rows = [];
    $total = 0;
    $total_amount = 0;
    foreach ($this->memberStorage->adminMemberAmountPaidByTypeSummaryLoad($eid) as $entry) {
      // Replace type code with description.
      if (isset($types->types[$entry['member_type']])) {
        $entry['member_type'] = (isset($types->types[$entry['member_type']]) ? $types->types[$entry['member_type']]->name : $entry['member_type']);
      }
      // Calculate total received at that rate.
      $total_paid = $entry['member_price'] * $entry['num'];
      $entry['total_paid'] = number_format($total_paid, 2);
      // Sanitize each entry.
      $rows[] = [
        ['data' => isset($types->types[$entry['member_type']]) ? $types->types[$entry['member_type']]->name : $entry['member_type']],
        ['data' => $entry['member_price']],
        ['data' => $entry['num']],
        ['data' => number_format($total_paid, 2)],
      ];

      // Add to totals.
      $total += $entry['num'];
      $total_amount += $total_paid;
    }
    // Add a row for the total.
    $footer = ConregTable::totalFooterRow([$this->t('Total'), '', $total, number_format($total_amount, 2)]);
    $element['type_amount_paid_summary'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-summary-by-amount-paid-per-type', TableRole::Summary),
      '#rows' => $rows,
      '#footer' => $footer,
      '#empty' => $this->t('No entries available.'),
    ];

    return $element;
  }

  /**
   * Add a summary by date joined to render array.
   */
  public function memberAdminMemberListByDateSummary(int $eid): array {
    $element = [
      '#prefix' => '<div class="conreg-table-section conreg-table-section--by-date">',
      '#suffix' => '</div>',
    ];

    $element['message_by_date'] = [
      '#markup' => $this->t('Summary by date joined'),
      '#prefix' => '<h3>',
      '#suffix' => '</h3>',
    ];

    $months = DateHelper::monthNames();
    $headers = [
      $this->t('Year'),
      $this->t('Month'),
      $this->t('Number of members'),
      $this->t('Total Paid'),
      $this->t('Cumulative members'),
      $this->t('Cumulative Total Paid'),
    ];
    $rows = [];
    $total = 0;
    $total_amount = 0;
    foreach ($this->memberStorage->adminMemberByDateSummaryLoad($eid) as $entry) {
      // Convert month to name.
      $entry['month'] = $months[$entry['month']];
      $total += $entry['num'];
      $total_amount += $entry['total_paid'];
      // Sanitize each entry.
      $rows[] = [
        ['data' => $entry['year']],
        ['data' => $entry['month']],
        ['data' => $entry['num']],
        ['data' => number_format($entry['total_paid'], 2)],
        ['data' => $total],
        ['data' => number_format($total_amount, 2)],
      ];
    }
    // Add a row for the total.
    $footer = ConregTable::totalFooterRow([
      $this->t('Total'),
      '',
      $total,
      number_format($total_amount, 2),
      $total,
      number_format($total_amount, 2),
    ]);
    $element['by_date_summary'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('member-summary-by-date', TableRole::Summary),
      '#rows' => $rows,
      '#footer' => $footer,
      '#empty' => $this->t('No entries available.'),
    ];

    return $element;
  }

  /**
   * Render a list of paid convention members in the database.
   */
  public function memberAdminMemberList(int $eid) {
    $config = ConregConfig::getConfig($eid);
    $countryOptions = $this->conregOptions->memberCountries($eid);
    $types = $this->conregOptions->memberTypes($eid);
    $badgeTypes = $this->conregOptions->badgeTypes($eid);
    $days = $this->conregOptions->days($eid);
    $communicationsOptions = $this->conregOptions->communicationMethod($eid);
    $displayOptions = $this->conregOptions->display($eid);
    $yesNo = $this->conregOptions->yesNo();

    $content = [
      '#cache' => [
        'tags' => ['event:' . $eid . ':members'],
        'contexts' => ['url.query_args:sort', 'url.query_args:order'],
        'max-age' => Cache::PERMANENT,
      ],
      '#attached' => [
        'library' => ['conreg/conreg_tables'],
      ],
    ];

    $pageOptions = [];
    switch ($this->requestStack->getCurrentRequest()->query->get('sort') ?? '') {
      case 'desc':
        $direction = 'DESC';
        $pageOptions['sort'] = 'desc';
        break;

      default:
        $direction = 'ASC';
        break;
    }
    switch ($this->requestStack->getCurrentRequest()->query->get('order') ?? '') {
      case 'MID':
        $order = 'm.mid';
        $pageOptions['order'] = 'MID';
        break;

      case 'First name':
        $order = 'm.first_name';
        $pageOptions['order'] = 'First name';
        break;

      case 'Last name':
        $order = 'm.last_name';
        $pageOptions['order'] = 'Last name';
        break;

      case 'Badge name':
        $order = 'm.badge_name';
        $pageOptions['order'] = 'Badge name';
        break;

      case 'Email':
        $order = 'm.email';
        $pageOptions['order'] = 'Email';
        break;

      default:
        $order = 'member_no';
        break;
    }

    $content['message'] = [
      '#markup' => $this->t('Here is a list of all paid convention members.'),
    ];

    $content['copy'] = [
      '#type' => 'button',
      '#value' => $this->t('Copy to clipboard'),
      '#attributes' => ['class' => ['table-copy']],
    ];

    $content['member_summary'] = $this->memberAdminMemberListSummary($eid, FALSE);

    $rows = [];
    $headers = [
      'member_type' => [
        'data' => $this->t('Member type'),
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'days' => [
        'data' => $this->t('Days'),
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'member_no' => [
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
      'badge_type' => [
        'data' => $this->t('Badge type'),
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'street' => $this->t('Street'),
      'street2' => $this->t('Street line 2'),
      'city' => $this->t('City'),
      'county' => $this->t('County'),
      'postcode' => $this->t('Postcode'),
      'country' => $this->t('Country'),
      'phone' => $this->t('Phone'),
      'dob' => $this->t('Birth Date'),
      'age' => $this->t('Age'),
      'display' => [
        'data' => $this->t('Display'),
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'comm_method' => $this->t('Communication Method'),
      'paid' => $this->t('Paid'),
      'price' => $this->t('Price'),
      'comments' => $this->t('Comments'),
      'approved' => $this->t('Approved'),
      'mid' => [
        'data' => $this->t('Internal ID'),
        'field' => 'm.mid',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'joined' => $this->t('Date joined'),
    ];

    foreach ($this->memberStorage->adminPaidMemberListLoad($eid, $direction, $order) as $entry) {
      if (!empty($entry['member_no'])) {
        $entry['member_no'] = $this->showBadgeNumber($entry, $config);
      }
      if (!empty($entry['days'])) {
        $dayDescriptions = [];
        foreach (explode('|', $entry['days']) as $day) {
          $dayDescriptions[] = $days[$day] ?? $day;
        }
        $entry['days'] = implode(', ', $dayDescriptions);
      }
      $entry['member_type'] = isset($types->types[$entry['member_type']]) ? $types->types[$entry['member_type']]->name : $entry['member_type'];
      $entry['badge_type'] = $badgeTypes[$entry['badge_type']] ?? $entry['badge_type'];
      $entry['country'] = $countryOptions[$entry['country']] ?? $entry['country'];
      $entry['communication_method'] = $communicationsOptions[$entry['communication_method']] ?? $entry['communication_method'];
      $entry['display'] = $displayOptions[$entry['display']] ?? $entry['display'];
      $entry['is_paid'] = $yesNo[$entry['is_paid']] ?? $entry['is_paid'];
      $entry['is_approved'] = $yesNo[$entry['is_approved']] ?? $entry['is_approved'];
      // Sanitize each entry.
      $rows[] = $entry;
    }
    $content['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('admin-member-list', TableRole::ListTable),
      '#rows' => $rows,
      '#empty' => $this->t('No entries available.'),
      '#sticky' => TRUE,
    ];
    // Don't cache this page.
    $content['#cache']['max-age'] = 0;

    return $content;
  }

  /**
   * Render a summary convention members in the database.
   */
  public function memberAdminMemberSummary(int $eid) {
    $event = $this->eventStorage->load(['eid' => $eid]);
    $content = [
      '#title' => $this->t('@event_name Member Summary', ['@event_name' => $event['event_name']]),
      '#cache' => [
        'tags' => ['event:' . $eid . ':members'],
        'max-age' => Cache::PERMANENT,
      ],
      '#attached' => [
        'library' => ['conreg/conreg_tables'],
      ],
    ];

    $content['copy'] = [
      '#type' => 'button',
      '#value' => $this->t('Copy to clipboard'),
      '#attributes' => ['class' => ['table-copy']],
    ];

    $content['member_summary'] = $this->memberAdminMemberListSummary($eid);
    $content['badge_summary'] = $this->memberAdminMemberListBadgeSummary($eid);
    $content['days_summary'] = $this->memberAdminMemberListDaysSummary($eid);
    $content['payment_method_summary'] = $this->memberAdminMemberListPaymentMethodSummary($eid);
    $content['amount_paid_summary'] = $this->memberAdminMemberListAmountPaidSummary($eid);
    $content['type_amount_paid_summary'] = $this->memberAdminMemberListAmountPaidByTypeSummary($eid);
    $content['by_date_summary'] = $this->memberAdminMemberListByDateSummary($eid);

    // Don't cache this page.
    $content['#cache']['max-age'] = 0;

    return $content;
  }

  /**
   * Return a list of member add-ons.
   */
  public function memberAdminMemberAddOns(int $eid) {
    $content = [
      '#cache' => [
        'tags' => ['event:' . $eid . ':members'],
        'max-age' => Cache::PERMANENT,
      ],
    ];

    $content['message'] = [
      '#markup' => $this->t('List of members with add-ons.'),
    ];

    $rows = [];
    $headers = [
      $this->t('First Name'),
      $this->t('Last Name'),
      $this->t('email'),
      $this->t('Add-on Option'),
      $this->t('Add-on Detail'),
      $this->t('Add-on Price'),
    ];

    $total = 0;

    foreach ($this->memberStorage->adminMemberAddOns($eid) as $entry) {
      $total += $entry['add_on_price'];
      $rows[] = $entry;
    }

    // Add a row for the total.
    $footer = ConregTable::totalFooterRow([$this->t('Total'), '', '', '', '', number_format($total, 2)]);

    $content['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('admin-member-addons', TableRole::ListTable),
      '#rows' => $rows,
      '#footer' => $footer,
      '#empty' => $this->t('No entries available.'),
      '#sticky' => TRUE,
    ];
    // Don't cache this page.
    $content['#cache']['max-age'] = 0;

    return $content;
  }

  /**
   * Display a list of child members and their ages.
   */
  public function memberAdminChildMemberAges(int $eid) {
    $content = [
      '#cache' => [
        'tags' => ['event:' . $eid . ':members'],
        'max-age' => Cache::PERMANENT,
      ],
    ];

    $content['message'] = [
      '#markup' => $this->t('List of members with add-ons.'),
    ];

    $rows = [];
    $headers = [
      $this->t('Member No'),
      $this->t('First Name'),
      $this->t('Last Name'),
      $this->t('email'),
      $this->t('Member Type'),
      $this->t('Age'),
      $this->t('Parent First Name'),
      $this->t('Parent Last Name'),
      $this->t('Parent email'),
    ];

    foreach ($this->memberStorage->adminMemberChildMembers($eid) as $entry) {
      // Sanitize each entry.
      $rows[] = $entry;
    }

    $content['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#attributes' => ConregTable::attributes('admin-member-child-ages', TableRole::ListTable),
      '#rows' => $rows,
      '#empty' => $this->t('No entries available.'),
      '#sticky' => TRUE,
    ];
    // Don't cache this page.
    $content['#cache']['max-age'] = 0;

    return $content;
  }

}
