<?php

namespace Drupal\conreg\Service;

use Drupal\conreg\ConregConfig;
use Drupal\conreg\ConregOptions;
use Drupal\conreg\FieldOptions;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Builds the [conreg:member-details] token.
 *
 * A full report of every member in a registration group - pricing,
 * add-ons, and per-field labels sourced from the event's configured
 * member classes. Ported from
 * ConregTokens::replaceMemberCodes()/getMemberDetailsToken(). Deliberately
 * not a pure array lookup like ConregTokenHooks::MEMBER_FIELDS - it needs
 * live event config and DB lookups (member classes, field options), so it
 * can't be Unit-tested the way the rest of the "member" tokens are.
 */
class MemberDetailsFormatter {

  use StringTranslationTrait;

  public function __construct(
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Fields to display for each member.
   *
   * Keyed by field name, valued by where its label lives in the event's
   * member class configuration.
   */
  private const CONFIRM_FIELDS = [
    'member_type' => 'fields.membership_type',
    'days' => 'fields.membership_days',
    'first_name' => 'fields.first_name',
    'last_name' => 'fields.last_name',
    'badge_name' => 'fields.badge_name',
    'email' => 'fields.email',
    'display' => 'fields.display',
    'communication_method' => 'fields.communication_method',
    'street' => 'fields.street',
    'street2' => 'fields.street2',
    'city' => 'fields.city',
    'county' => 'fields.county',
    'postcode' => 'fields.postcode',
    'country' => 'fields.country',
    'phone' => 'fields.phone',
    'birth_date' => 'fields.birth_date',
    'age' => 'fields.age',
  ];

  /**
   * Inline styles for the HTML table - email clients need inline CSS.
   *
   * Most email clients (Outlook chief among them) strip <style> blocks and
   * external stylesheets, and a bare unstyled <table> has no borders or
   * cell padding, so its cells render pressed up against each other with
   * no visible separation. Tables are still the right tool for this kind
   * of tabular layout in HTML email (broad, ancient client support,
   * unlike flexbox/grid) - they just need every style written inline.
   */
  private const TABLE_STYLE = 'border-collapse:collapse;width:100%;max-width:600px;';
  private const SECTION_CELL_STYLE = 'padding:8px 10px;text-align:left;background-color:#eeeeee;border:1px solid #cccccc;';
  private const LABEL_CELL_STYLE = 'padding:6px 10px;border:1px solid #dddddd;text-align:left;vertical-align:top;width:45%;';
  private const VALUE_CELL_STYLE = 'padding:6px 10px;border:1px solid #dddddd;text-align:left;vertical-align:top;';

  /**
   * Builds the member details report for a whole group of members.
   *
   * Expects members already run through
   * ConregTokens::replaceMemberCodes() (labels/currency resolved,
   * `addons`/`raw_member_type` already present) - this is exactly the
   * shape ConregEmailer already builds for the other "member" tokens.
   *
   * @param int $eid
   *   The event ID.
   * @param array[] $members
   *   The members to report on, lead first.
   *
   * @return array{html: string, plain: string}
   *   The HTML and plain-text renderings of the report.
   */
  public function build(int $eid, array $members): array {
    if (empty($members)) {
      return ['html' => '', 'plain' => ''];
    }

    $config = ConregConfig::getConfig($eid);
    $types = ConregOptions::memberTypes($eid, $config);
    $memberClasses = ConregOptions::memberClasses($eid, $config);
    $fieldOptions = FieldOptions::getFieldOptions($eid);

    $html = '';
    $plain = '';
    $currentRegDateKey = NULL;

    $memberSeq = 0;
    $paymentAmount = '';
    foreach ($members as $curMember) {
      // Members registered together share one "Registered on" heading;
      // members combined from separate registrations (e.g. the "email a
      // member" admin form merging several registrations under one email
      // address) may have joined on different dates, so break into a new
      // heading - and a new table - whenever the date changes. Member
      // numbering keeps counting across the break.
      $regDateKey = $this->dateFormatter->format($curMember['join_date'], 'custom', 'Y-m-d');
      if ($regDateKey !== $currentRegDateKey) {
        if ($currentRegDateKey !== NULL) {
          $html .= '</table>';
        }
        $currentRegDateKey = $regDateKey;
        $regDate = $this->t('Registered on @date', ['@date' => $this->dateFormatter->format($curMember['join_date'], 'custom', 'D, j M Y')]);
        $html .= '<h3>' . $regDate . '</h3><table style="' . self::TABLE_STYLE . '">';
        $plain .= "\n$regDate\n";
      }

      $memberType = $curMember['raw_member_type'];
      $curMemberClassRef = (!empty($memberType) && isset($types->types[$memberType]))
        ? $types->types[$memberType]->memberClass
        : array_key_first($memberClasses->classes);
      $curMemberClass = $memberClasses->classes[$curMemberClassRef];
      $memberOptions = $fieldOptions->getMemberOptions($curMember['mid']);

      $memberSeq++;
      $memberHeading = $this->t('Member @number', ['@number' => $memberSeq]);
      $html .= $this->sectionRow($memberHeading);
      $plain .= "\n$memberHeading\n";
      if (!empty($curMember['member_no'])) {
        $label = $this->t('Member Number');
        $html .= $this->row($label, $curMember['member_no']);
        $plain .= $label . ":\t" . $curMember['member_no'] . "\n";
      }

      foreach (self::CONFIRM_FIELDS as $key => $configPath) {
        [$section, $entry] = explode('.', $configPath);
        if (empty($curMemberClass->$section->$entry)) {
          continue;
        }
        // Override name for badge name field, as we don't want it to say
        // "Custom badge name".
        $label = $key === 'badge_name' ? $this->t('Name on badge') : $curMemberClass->$section->$entry;
        $value = $curMember[$key] ?? '';
        $html .= $this->row($label, $value);
        $plain .= $label . ":\t" . $value . "\n";

        if (isset($memberOptions[$key])) {
          [$optionHtml, $optionPlain] = $this->renderMemberOption($memberOptions[$key]);
          $html .= $optionHtml;
          $plain .= $optionPlain;
          unset($memberOptions[$key]);
        }
      }

      // Any remaining member options not tied to one of the fields above.
      foreach ($memberOptions as $memberOption) {
        [$optionHtml, $optionPlain] = $this->renderMemberOption($memberOption);
        $html .= $optionHtml;
        $plain .= $optionPlain;
      }

      $label = $this->t('Price for member');
      $html .= $this->row($label, $curMember['member_price']);
      $plain .= $label . ":\t" . $curMember['member_price'] . "\n";

      foreach ($curMember['addons'] as $addon) {
        if (!empty($addon->label) && !empty($addon->option)) {
          $addOnName = $this->t('Add-on: @addon', ['@addon' => $addon->label]);
          $html .= $this->row($addOnName, $addon->option);
          $plain .= $addOnName . ":\t" . $addon->option . "\n";
        }
        if (!empty($addon->info)) {
          $html .= $this->row($addon->info, $addon->info);
          $plain .= $addon->info . ":\t" . $addon->info . "\n";
        }
        if (!empty($addon->free) && !empty($addon->amount)) {
          $addOnFree = $this->t('Add-on: @addon', ['@addon' => $addon->free]);
          $html .= $this->row($addOnFree, $addon->amount);
          $plain .= $addOnFree . ":\t" . $addon->amount . "\n";
        }
        if (!empty($addon->amount)) {
          $addOnPrice = $this->t('@addon price', ['@addon' => $addon->label]);
          $html .= $this->row($addOnPrice, $addon->amount);
          $plain .= $addOnPrice . ":\t" . $addon->amount . "\n";
        }
      }
      if (!empty($curMember['add_on_price'])) {
        $label = $this->t('Add-on Total for member');
        $html .= $this->row($label, $curMember['add_on_price']);
        $plain .= $label . ":\t" . $curMember['add_on_price'] . "\n";
      }
      $label = $this->t('Member Total');
      $html .= $this->row($label, $curMember['member_total']);
      $plain .= $label . ":\t" . $curMember['member_total'] . "\n";
      $paymentAmount = $curMember['payment_amount'];
    }

    $label = $this->t('Total');
    $html .= $this->sectionRow($label);
    $plain .= "\n$label\n";
    $label = $this->t('Total amount paid');
    $html .= $this->row($label, $paymentAmount);
    $plain .= $label . ":\t" . $paymentAmount . "\n";
    $html .= '</table>';

    return ['html' => $html, 'plain' => $plain];
  }

  /**
   * Renders one member option (and its Yes/detail rows) as [html, plain].
   *
   * @return array{0: string, 1: string}
   *   The [html, plain] pair.
   */
  protected function renderMemberOption(array $memberOption): array {
    $html = $this->spanRow($memberOption['title']);
    $plain = $memberOption['title'] . "\n";
    foreach ($memberOption['options'] as $option) {
      $html .= $this->row($option['option_title'], $this->t('Yes'));
      $plain .= $option['option_title'] . ":\t" . $this->t('Yes') . "\n";
      if (isset($option['option_detail'])) {
        $html .= $this->row($option['detail_title'], $option['option_detail']);
        $plain .= $option['detail_title'] . ":\t" . $option['option_detail'] . "\n";
      }
    }
    return [$html, $plain];
  }

  /**
   * Builds one styled `<tr>` with a label cell and a value cell.
   */
  private function row(mixed $label, mixed $value): string {
    return '<tr><td style="' . self::LABEL_CELL_STYLE . '">' . $label . '</td><td style="' . self::VALUE_CELL_STYLE . '">' . $value . '</td></tr>';
  }

  /**
   * Builds one styled, full-width section heading `<tr>` (e.g. "Member 1").
   */
  private function sectionRow(mixed $text): string {
    return '<tr><th colspan="2" style="' . self::SECTION_CELL_STYLE . '">' . $text . '</th></tr>';
  }

  /**
   * Builds one styled, full-width `<tr>` (e.g. a member option's title).
   */
  private function spanRow(mixed $text): string {
    return '<tr><td colspan="2" style="' . self::VALUE_CELL_STYLE . '">' . $text . '</td></tr>';
  }

}
