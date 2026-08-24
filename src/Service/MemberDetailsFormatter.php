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

    $regDate = $this->t('Registered on @date', ['@date' => $this->dateFormatter->format($members[0]['join_date'])]);
    $html = '<h3>' . $regDate . '</h3><table>';
    $plain = "\n$regDate\n";

    $memberSeq = 0;
    $paymentAmount = '';
    foreach ($members as $curMember) {
      $memberType = $curMember['raw_member_type'];
      $curMemberClassRef = (!empty($memberType) && isset($types->types[$memberType]))
        ? $types->types[$memberType]->memberClass
        : array_key_first($memberClasses->classes);
      $curMemberClass = $memberClasses->classes[$curMemberClassRef];
      $memberOptions = $fieldOptions->getMemberOptions($curMember['mid']);

      $memberSeq++;
      $memberHeading = $this->t('Member @seq', ['@seq' => $memberSeq]);
      $html .= '<tr><th colspan="2">' . $memberHeading . '</th></tr>';
      $plain .= "\n$memberHeading\n";
      if (!empty($curMember['member_no'])) {
        $label = $this->t('Member Number');
        $html .= '<tr><td>' . $label . '</td><td>' . $curMember['member_no'] . '</td></tr>';
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
        $html .= '<tr><td>' . $label . '</td><td>' . $value . '</td></tr>';
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
      $html .= '<tr><td>' . $label . '</td><td>' . $curMember['member_price'] . '</td></tr>';
      $plain .= $label . ":\t" . $curMember['member_price'] . "\n";

      foreach ($curMember['addons'] as $addon) {
        if (!empty($addon->label) && !empty($addon->option)) {
          $addOnName = $this->t('Add-on: @addon', ['@addon' => $addon->label]);
          $html .= '<tr><td>' . $addOnName . '</td><td>' . $addon->option . '</td></tr>';
          $plain .= $addOnName . ":\t" . $addon->option . "\n";
        }
        if (!empty($addon->info)) {
          $html .= '<tr><td>' . $addon->info . '</td><td>' . $addon->info . '</td></tr>';
          $plain .= $addon->info . ":\t" . $addon->info . "\n";
        }
        if (!empty($addon->free) && !empty($addon->amount)) {
          $addOnFree = $this->t('Add-on: @addon', ['@addon' => $addon->free]);
          $html .= '<tr><td>' . $addOnFree . '</td><td>' . $addon->amount . '</td></tr>';
          $plain .= $addOnFree . ":\t" . $addon->amount . "\n";
        }
        if (!empty($addon->amount)) {
          $addOnPrice = $this->t('@addon price', ['@addon' => $addon->label]);
          $html .= '<tr><td>' . $addOnPrice . '</td><td>' . $addon->amount . '</td></tr>';
          $plain .= $addOnPrice . ":\t" . $addon->amount . "\n";
        }
      }
      if (!empty($curMember['add_on_price'])) {
        $label = $this->t('Add-on Total for member');
        $html .= '<tr><td>' . $label . '</td><td>' . $curMember['add_on_price'] . '</td></tr>';
        $plain .= $label . ":\t" . $curMember['add_on_price'] . "\n";
      }
      $label = $this->t('Member Total');
      $html .= '<tr><td>' . $label . '</td><td>' . $curMember['member_total'] . '</td></tr>';
      $plain .= $label . ":\t" . $curMember['member_total'] . "\n";
      $paymentAmount = $curMember['payment_amount'];
    }

    $label = $this->t('Total');
    $html .= '<tr><th colspan="2">' . $label . '</th></tr>';
    $plain .= "\n$label\n";
    $label = $this->t('Total amount paid');
    $html .= '<tr><td>' . $label . '</td><td>' . $paymentAmount . '</td></tr>';
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
    $html = '<tr><td colspan="2">' . $memberOption['title'] . '</td></tr>';
    $plain = $memberOption['title'] . "\n";
    foreach ($memberOption['options'] as $option) {
      $html .= '<tr><td>' . $option['option_title'] . '</td><td>' . $this->t('Yes') . '</td></tr>';
      $plain .= $option['option_title'] . ":\t" . $this->t('Yes') . "\n";
      if (isset($option['option_detail'])) {
        $html .= '<tr><td>' . $option['detail_title'] . '</td><td>' . $option['option_detail'] . '</td></tr>';
        $plain .= $option['detail_title'] . ":\t" . $option['option_detail'] . "\n";
      }
    }
    return [$html, $plain];
  }

}
