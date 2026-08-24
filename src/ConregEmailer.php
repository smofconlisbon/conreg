<?php

namespace Drupal\conreg;

use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;
use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\Service\MemberPresenter;
use Drupal\Core\StreamWrapper\PublicStream;

// Define message formats.
define('CONREG_FORMAT_PLAIN', 'text/plain');
define('CONREG_FORMAT_HTML', 'text/html');

/**
 * Class for sending emails.
 */
class ConregEmailer {

  /**
   * Function to create an email for specified member.
   *
   * @param string $message
   *   The message to send.
   * @param array $params
   *   Array of message parameters. In addition to 'eid', 'mid', 'to',
   *   'from', 'subject', and 'body', callers may pass 'token_data': an
   *   array merged into the token replacement $data, letting submodules
   *   (Discord, PlanZ, ...) expose their own token context without this
   *   class needing to know about them.
   */
  public static function createEmail(&$message, array $params) {
    // Only proceed if Event provided.
    if (isset($params['eid'])) {
      $eid = $params['eid'];
      $config = \Drupal::config('conreg.settings.' . $eid);
      $presenter = \Drupal::service(MemberPresenter::class);

      // Only fetch member details if mid set. mid may be a single member
      // ID or an array of them - the admin "email member" form combines
      // multiple separate registrations under the same email address
      // into one email, each contributing its own group of members.
      $members = [];
      $primary = NULL;
      $leaderData = [];
      $primaryMid = NULL;
      if (isset($params['mid'])) {
        $mids = is_array($params['mid']) ? $params['mid'] : [$params['mid']];
        foreach ($mids as $mid) {
          $group = $presenter->loadGroup($eid, (int) $mid);
          $presenter->present($eid, $group);
          $members = array_merge($members, $group);
        }
        if (isset($members[0])) {
          $primaryMid = (int) $mids[0];
          $primary = $members[0];
          $loginData = $presenter->primaryMemberLoginData($primary);
          $leaderData = $presenter->leaderData($eid, $primary);
          $members[0] = $primary + $loginData + $leaderData;
        }
      }

      // If no address, use lead member address, and add a note.
      if (empty($params['to']) && $primary !== NULL) {
        if (!empty($primary['email'])) {
          $params['to'] = $primary['email'];
        }
        else {
          $params['to'] = $leaderData['lead_email'] ?? '';
          $params['body'] = t('<p>Note: we are you writing to you as contact for [conreg:member:full-name].</p>') . $params['body'];
        }
      }
      // Set the message type.
      $message['headers']['Content-Type'] = CONREG_FORMAT_HTML;
      // Set member values in params, for callers that inspect them
      // directly (e.g. MemberEmail's admin preview).
      if ($primary !== NULL) {
        foreach ($primary as $key => $val) {
          $params[$key] = $val;
        }
      }

      $drupalTokens = \Drupal::token();
      $event = \Drupal::service(EventStorage::class)->load(['eid' => $eid]);
      $drupalTokenData = [
        'event' => [
          'eid' => $eid,
          'name' => $event['event_name'],
          'email' => $config->get('confirmation.from_email'),
        ],
        'members' => array_map(fn (array $member) => Member::newMember($member), $members),
      ] + ($params['token_data'] ?? []);

      // Store params in message to return.
      $message['params'] = $params;
      $message['subject'] = $drupalTokens->replace($params['subject'], $drupalTokenData);
      $body = [$drupalTokens->replace(preg_replace("/[\n\r]+/", '', $params['body']), $drupalTokenData)];
      $message['preview'] = implode("\n", $body);

      // Only attach badge image if referenced in body.
      if (strpos($body[0], '[badge]') !== FALSE) {
        // Set ID for attachment.
        $badge_id = "conreg-badge" . $primaryMid;
        $badge = '<img src="cid:' . $badge_id . '" />';

        // Prepare image attachment.
        $badgePath = PublicStream::basePath() . '/badges/' . $eid;
        $badgeFile = 'mid' . $primaryMid . '.png';
        // Create attachment object.
        $file = new \stdClass();
        $file->cid = $badge_id;
        // File path.
        $file->uri = $badgePath . '/' . $badgeFile;
        // File name.
        $file->filename = $badgeFile;
        // File mime type.
        $file->filemime = 'image/png';
        // Add object to images array.
        $message['params']['images'][] = $file;
      }

      if ($config->get('confirmation.format_html')) {
        // Split body into an array.
        if (!empty($badge)) {
          $body[0] = str_replace('[badge]', $badge, $body[0]);
        }
        $message['body'] = array_map(function ($body) {
          return Markup::create($body);
        }, $body);
      }
      else {
        // Plain text version of body. replacePlain() (not replace()) so
        // a plain string replacement value is used as-is rather than
        // HTML-escaped - replace() always treats replacement values as
        // destined for HTML output.
        $plainBody = $drupalTokens->replacePlain($params['body'], $drupalTokenData, ['plain_text' => TRUE]);
        $message['body'][] = Html::escape($plainBody);
      }
      if (empty($params['from'])) {
        $from = $config->get('confirmation.from_name') . " <" . $config->get('confirmation.from_email') . ">";
        $message['from'] = $from;
        $message['headers']['From'] = $from;
      }
      else {
        $message['from'] = $params['from'];
        $message['headers']['From'] = $params['from'];
      }
    }
  }

}
