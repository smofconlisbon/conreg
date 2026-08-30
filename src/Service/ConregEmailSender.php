<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Utility\Token;
use Drupal\easy_email\Entity\EasyEmailInterface;
use Drupal\easy_email\Service\EmailHandlerInterface;

/**
 * Builds and sends conreg emails via Easy Email in the right language.
 *
 * Centralizes what every conreg email call site needs: wrap
 * EmailHandlerInterface::createEmail() in a config-override-language block
 * so the recipient's own language's translated EasyEmailType text gets
 * used (mirroring the pattern core's user module uses in
 * user_mail()/UserHooks::mail() - see web/core/modules/user/src/Hook/
 * UserHooks.php), stamp field_conreg_eid/field_conreg_mid/
 * field_conreg_extra_token_data so ConregTokenHooks/ConregPlanzHooks/
 * ConregDiscordTokenHooks can resolve their tokens via EmailTokenContext,
 * and pre-populate a real plain-text body (see populatePlainBody()).
 */
class ConregEmailSender {

  public function __construct(
    protected EmailHandlerInterface $emailHandler,
    protected LanguageManagerInterface $languageManager,
    protected Token $token,
  ) {}

  /**
   * Builds (but does not send) an email entity for the given bundle.
   *
   * @param string $bundle
   *   The easy_email_type ID to use.
   * @param string $recipient
   *   The recipient email address.
   * @param int $eid
   *   The event ID, for [conreg:*] token resolution.
   * @param int[] $mids
   *   Member ID(s), for [conreg:*] token resolution. Empty when the email
   *   has no associated member (e.g. the member-check "not found" case).
   * @param string|null $langcode
   *   The language to render the template in. Defaults to the site's
   *   default language when NULL or not a configured language.
   * @param array $extraTokenData
   *   Extra token data (e.g. ['planz' => [...]]) generated at send time
   *   that can't be recomputed later from eid/mid alone - JSON-encoded
   *   into field_conreg_extra_token_data.
   *
   * @return \Drupal\easy_email\Entity\EasyEmailInterface
   *   The built (unsaved) email entity.
   */
  public function build(string $bundle, string $recipient, int $eid, array $mids = [], ?string $langcode = NULL, array $extraTokenData = []): EasyEmailInterface {
    $language = ($langcode ? $this->languageManager->getLanguage($langcode) : NULL) ?? $this->languageManager->getDefaultLanguage();
    $original = $this->languageManager->getConfigOverrideLanguage();
    $this->languageManager->setConfigOverrideLanguage($language);

    $values = [
      'type' => $bundle,
      'recipient_address' => [$recipient],
      'field_conreg_eid' => $eid,
      'field_conreg_mid' => $mids,
    ];
    if ($extraTokenData) {
      $values['field_conreg_extra_token_data'] = json_encode($extraTokenData);
    }
    $email = $this->emailHandler->createEmail($values);
    $this->populatePlainBody($email);

    $this->languageManager->setConfigOverrideLanguage($original);
    return $email;
  }

  /**
   * Sets a real plain-text body, instead of Easy Email's own auto-conversion.
   *
   * Easy Email's own HTML-to-plain-text conversion (when an EasyEmailType
   * has "Generate plain text body" enabled) runs on the final rendered
   * HTML, after tokens are resolved - by then, [conreg:member-details] has
   * already become an HTML <table>, and generic HTML-to-text converters
   * (Drupal core's MailFormatHelper::htmlToText() included) don't insert
   * any separators between table cells, so a member details table renders
   * as one run-on line of squashed text.
   *
   * Instead, this resolves conreg tokens itself with the 'plain_text'
   * token option set (the option MemberDetailsFormatter/ConregTokenHooks
   * use to render member-details as an indented text block instead of a
   * table - see ConregTokenHooks::memberDetailsValue()) against the raw,
   * unresolved template text, then runs the result through
   * MailFormatHelper::htmlToText() to turn the surrounding <p>/<br> markup
   * into plain paragraphs. The corresponding EasyEmailType must have
   * "Generate plain text body" turned off, or Easy Email will discard this
   * and fall back to its own conversion anyway.
   */
  public function populatePlainBody(EasyEmailInterface $email): void {
    if (!$email->hasField('body_plain')) {
      return;
    }
    $htmlBody = $email->getHtmlBody();
    if (empty($htmlBody['value'])) {
      return;
    }
    $plainText = $this->token->replace($htmlBody['value'], ['easy_email' => $email], ['plain_text' => TRUE]);
    $email->setPlainBody(trim(MailFormatHelper::htmlToText((string) $plainText)));
  }

  /**
   * Builds and immediately sends an email for the given bundle.
   *
   * See build() for parameter details.
   */
  public function send(string $bundle, string $recipient, int $eid, array $mids = [], ?string $langcode = NULL, array $extraTokenData = [], bool $saveEmailEntity = FALSE): EasyEmailInterface {
    $email = $this->build($bundle, $recipient, $eid, $mids, $langcode, $extraTokenData);
    $this->emailHandler->sendEmail($email, [], FALSE, $saveEmailEntity);
    return $email;
  }

  /**
   * Sends an already-built email entity, e.g. after overriding a field.
   */
  public function sendEmail(EasyEmailInterface $email, bool $saveEmailEntity = FALSE): void {
    $this->emailHandler->sendEmail($email, [], FALSE, $saveEmailEntity);
  }

}
