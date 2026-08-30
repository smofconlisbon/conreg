<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\conreg\Member;
use Drupal\easy_email\Entity\EasyEmailInterface;

/**
 * Builds the `event`/`members` token data ConregTokenHooks resolves against.
 *
 * Extracted from ConregEmailer::createEmail(), which built this data inline
 * before calling \Drupal::token()->replace() directly. Easy Email's
 * EmailTokenEvaluator only ever passes `['easy_email' => $email]` as token
 * data (see EmailTokenEvaluator::replaceTokens()), so ConregTokenHooks
 * derives `event`/`members` from an easy_email entity via buildFromEmail()
 * instead, using the eid/mid stashed on it by field_conreg_eid/
 * field_conreg_mid.
 */
class EmailTokenContext {

  /**
   * Per-entity cache of built token data, keyed by entity id or object id.
   *
   * EvaluateTokens() calls replaceTokens() around nine times per send
   * (subject, recipient/cc/bcc, from, reply-to, html body, plain body,
   * inbox preview, attachment path), each of which can trigger this build -
   * avoid re-running the member group/presenter DB work that many times.
   *
   * @var array[]
   */
  protected array $cache = [];

  public function __construct(
    protected EventStorage $eventStorage,
    protected MemberPresenter $memberPresenter,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds token data for an event and one or more member IDs.
   *
   * @param int $eid
   *   The event ID.
   * @param int[] $mids
   *   Member IDs. Each may be a separate registration group (the admin
   *   "email member" form combines multiple registrations under the same
   *   email address into one email) - every group's members are merged
   *   into a single flat list, with the first mid's lead member treated as
   *   the primary/addressee.
   *
   * @return array
   *   `event` (eid, name, email) and `members` (Member objects, primary
   *   first), ready to merge into Token::replace() data.
   */
  public function build(int $eid, array $mids): array {
    $members = [];
    foreach ($mids as $mid) {
      $group = $this->memberPresenter->loadGroup($eid, (int) $mid);
      $this->memberPresenter->present($eid, $group);
      $members = array_merge($members, $group);
    }
    if (isset($members[0])) {
      $primary = $members[0];
      $loginData = $this->memberPresenter->primaryMemberLoginData($primary);
      $leaderData = $this->memberPresenter->leaderData($eid, $primary);
      $members[0] = $primary + $loginData + $leaderData;
    }

    $event = $this->eventStorage->load(['eid' => $eid]);
    $config = $this->configFactory->get('conreg.settings.' . $eid);

    return [
      'event' => [
        'eid' => $eid,
        'name' => $event['event_name'] ?? '',
        'email' => $config->get('confirmation.from_email'),
      ],
      'members' => array_map(fn (array $member) => Member::newMember($member), $members),
    ];
  }

  /**
   * Builds token data from an easy_email entity's field_conreg_eid/_mid.
   *
   * @param \Drupal\easy_email\Entity\EasyEmailInterface $email
   *   The email entity being sent or previewed.
   *
   * @return array
   *   Same shape as build(), or an empty array if the entity has no
   *   field_conreg_eid value set (e.g. a bundle that doesn't use these
   *   fields).
   */
  public function buildFromEmail(EasyEmailInterface $email): array {
    $cacheKey = $email->id() ?? spl_object_id($email);
    if (isset($this->cache[$cacheKey])) {
      return $this->cache[$cacheKey];
    }

    $data = [];
    if ($email->hasField('field_conreg_eid') && !$email->get('field_conreg_eid')->isEmpty()) {
      $eid = (int) $email->get('field_conreg_eid')->value;
      $mids = $email->hasField('field_conreg_mid')
        ? array_map(fn (array $item) => (int) $item['value'], $email->get('field_conreg_mid')->getValue())
        : [];
      $data = $this->build($eid, $mids);
    }

    return $this->cache[$cacheKey] = $data;
  }

}
