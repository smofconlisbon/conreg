<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\conreg\Addons;
use Drupal\conreg\ConregConfig;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;

/**
 * Loads and prepares member data for display (emails, tokens).
 *
 * Ported from ConregTokens's constructor, replaceMemberCodes(), and
 * loadMemberGroup() - the pieces of the old token system that do real
 * work (DB reads/writes, config-driven label lookups) rather than simple
 * field lookups. ConregTokenHooks reads the results of this service via
 * ConregEmailer; it doesn't call this service directly, to stay
 * container-free itself.
 */
class MemberPresenter {

  /**
   * How long a self-service login link stays valid, in seconds.
   *
   * @todo Currently fixed. Make duration configurable.
   */
  private const LOGIN_EXPIRY_SECONDS = 604800;

  public function __construct(
    protected MemberStorage $memberStorage,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected ConregOptions $conregOptions,
  ) {}

  /**
   * Loads a member plus any other members they registered.
   *
   * @param int $eid
   *   The event ID.
   * @param int $mid
   *   The member ID.
   *
   * @return array[]
   *   Members in the group, lead first.
   */
  public function loadGroup(int $eid, int $mid): array {
    $members = $this->memberStorage->loadAll([
      'eid' => $eid,
      'mid' => $mid,
      'is_deleted' => 0,
    ]);
    // Get all members registered by subject member.
    $groupMembers = $this->memberStorage->loadAll([
      'eid' => $eid,
      'lead_mid' => $mid,
      'is_deleted' => 0,
    ]);
    // Remove the lead member from the group so they aren't duplicated.
    $groupMembers = array_filter($groupMembers, fn($member) => $member['mid'] != $mid);
    // Combine the lead member and the group, ensuring lead member is first.
    return array_merge($members, $groupMembers);
  }

  /**
   * Resolves codes to display values in place, for a whole group.
   *
   * Expands member-type/country/display/communication-method codes to
   * labels, formats prices with the event's currency symbol, formats the
   * badge/member number, and attaches each member's paid add-ons -
   * exactly what ConregTokens::replaceMemberCodes() did.
   *
   * @param int $eid
   *   The event ID.
   * @param array[] $members
   *   Members to resolve, modified in place.
   */
  public function present(int $eid, array &$members): void {
    $config = ConregConfig::getConfig($eid);
    $symbol = $config->get('payments.symbol');
    $digits = $config->get('member_no_digits');
    $types = $this->conregOptions->memberTypes($eid);
    $days = $this->conregOptions->days($eid);
    $displayOptions = $this->conregOptions->display($eid);
    $communicationOptions = $this->conregOptions->communicationMethod($eid);
    $countryOptions = $this->conregOptions->memberCountries($eid);
    $yesNoOptions = $this->conregOptions->yesNo();

    // Loop once to get the correct payment total.
    $payAmount = 0;
    foreach ($members as $index => $val) {
      // Get add ons and add up price.
      $addons = Addons::getMemberAddons($config, (int) $val['mid']);
      $members[$index]['addons'] = $addons;
      $members[$index]['add_on_price'] = 0;
      foreach ($addons as $addon) {
        $members[$index]['add_on_price'] += $addon->value;
      }
      $members[$index]['member_total'] = $val['member_price'] + $members[$index]['add_on_price'];
      $payAmount += $members[$index]['member_total'];
    }

    // Loop through members and set payment total.
    foreach ($members as $index => $val) {
      // If member number is zero, replace with blank.
      if ($members[$index]['member_no'] == 0) {
        $members[$index]['member_no'] = '';
      }
      else {
        $members[$index]['member_no'] = $members[$index]['badge_type'] . sprintf("%0" . $digits . "d", $members[$index]['member_no']);
      }
      // Expand list values and add currency symbol.
      if (!empty($members[$index]['days'])) {
        $dayDescriptions = [];
        foreach (explode('|', $members[$index]['days']) as $day) {
          $dayDescriptions[] = $days[$day] ?? $day;
        }
        $members[$index]['days'] = implode(', ', $dayDescriptions);
      }
      $members[$index]['is_approved'] = $yesNoOptions[$val['is_approved']]->render();
      $members[$index]['is_paid'] = $yesNoOptions[$val['is_paid']]->render();
      $members[$index]['is_deleted'] = $yesNoOptions[$val['is_deleted']]->render();
      $members[$index]['display'] = $displayOptions[$val['display']] ?? '';
      $members[$index]['member_price'] = $symbol . $val['member_price'];
      $members[$index]['member_total'] = $symbol . $val['member_total'];
      $members[$index]['payment_amount'] = $symbol . $payAmount;
      if (!empty($val['add_on_price'])) {
        $members[$index]['add_on_price'] = $symbol . $val['add_on_price'];
      }
      $members[$index]['raw_member_type'] = $members[$index]['member_type'];
      $members[$index]['member_type'] = (isset($types->types[$val['member_type']]) ? $types->types[$val['member_type']]->name : $val['member_type']);
      if (!empty($val['communication_method'])) {
        $members[$index]['communication_method'] = $communicationOptions[$val['communication_method']];
      }
      $members[$index]['country'] = $countryOptions[$val['country']] ?? '';
    }
  }

  /**
   * Builds the self-service login link data for one member.
   *
   * Generates and persists a random_key/login_exp_date if the member
   * doesn't already have a current one - a side effect, same as
   * ConregTokens's constructor had.
   *
   * @param array $member
   *   The member row (already run through present()). Its `random_key`
   *   is updated in place if freshly generated.
   *
   * @return array
   *   `login_url` (a plain, absolute URL - not HTML-anchor-wrapped;
   *   templates decide how to mark it up), `login_expiry` (raw
   *   timestamp), `login_expiry_medium`.
   */
  public function primaryMemberLoginData(array &$member): array {
    if (empty($member['random_key'])) {
      $randomKey = mt_rand();
      $this->memberStorage->update([
        'mid' => $member['mid'],
        'random_key' => $randomKey,
      ]);
      $member['random_key'] = $randomKey;
    }

    $expiryDate = $this->updateLoginExpiryDate((int) $member['mid']);
    $loginUrl = Url::fromRoute('conreg_login',
      [
        'mid' => $member['mid'],
        'key' => $member['random_key'],
        'expiry' => $expiryDate,
      ],
      ['absolute' => TRUE]
    )->toString();

    return [
      'login_url' => $loginUrl,
      'login_expiry' => $expiryDate,
      'login_expiry_medium' => $this->dateFormatter->format($expiryDate, 'medium'),
    ];
  }

  /**
   * Looks up the group leader's key/email for a member.
   *
   * @param int $eid
   *   The event ID.
   * @param array $member
   *   The member row (already run through present()).
   *
   * @return array
   *   `lead_key`, `lead_email` - the member's own values if they are
   *   already their own group's lead.
   */
  public function leaderData(int $eid, array $member): array {
    if ($member['mid'] == $member['lead_mid']) {
      $leader = $member;
    }
    else {
      $leader = $this->memberStorage->load([
        'eid' => $eid,
        'mid' => $member['lead_mid'],
        'is_deleted' => 0,
      ]);
    }
    return [
      'lead_key' => $leader['random_key'] ?? '',
      'lead_email' => $leader['email'] ?? '',
    ];
  }

  /**
   * Updates the date/time when the login link will expire.
   *
   * @param int $mid
   *   The member ID.
   *
   * @return int
   *   Unix timestamp of expiration time.
   */
  private function updateLoginExpiryDate(int $mid): int {
    $timeNow = $this->time->getRequestTime();

    // First check previous expiry time.
    $result = $this->memberStorage->load(['mid' => $mid]);
    $expiryTime = (int) $result['login_exp_date'];
    // Check if previous expiry date is more than 24 hours in the future.
    if ($expiryTime > $timeNow + 86400) {
      // Plenty of time left on previous key, just return it.
      return $expiryTime;
    }

    // Set expiry time to LOGIN_EXPIRY_SECONDS in the future, plus a
    // random jitter so logins generated in bulk won't share an expiry.
    $expiryTime = $timeNow + self::LOGIN_EXPIRY_SECONDS + rand(0, 3600);
    $this->memberStorage->update(['mid' => $mid, 'login_exp_date' => $expiryTime]);
    return $expiryTime;
  }

}
