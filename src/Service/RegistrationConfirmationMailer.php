<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\conreg\ConregOptions;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Sends the registration confirmation email (and admin copies) for a member.
 *
 * Shared by Checkout.php (online registration) and FanTable.php (admin
 * in-person registration), which previously duplicated this logic almost
 * verbatim.
 */
class RegistrationConfirmationMailer {

  public function __construct(
    protected ConregEmailSender $emailSender,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Sends the confirmation email to the member, plus any configured copies.
   *
   * @param array $member
   *   The member row (as returned by MemberStorage/Member casts), must
   *   include at least eid, mid, email, member_type, and optionally
   *   language.
   */
  public function send(array $member): void {
    $eid = (int) $member['eid'];
    $config = $this->configFactory->get('conreg.settings.' . $eid);
    $types = ConregOptions::memberTypes($eid, $config);

    $typeOverride = $types->types[$member['member_type']]->confirmation->easy_email_type ?? '';
    $bundle = $typeOverride ?: $config->get('confirmation.easy_email_type');
    $langcode = $member['language'] ?? NULL;

    if (!empty($member['email'])) {
      $this->emailSender->send($bundle, $member['email'], $eid, [(int) $member['mid']], $langcode);
    }

    if ($config->get('confirmation.copy_us') && $config->get('confirmation.from_email')) {
      $this->sendNotificationCopy($bundle, $config->get('confirmation.from_email'), $eid, (int) $member['mid'], $config->get('confirmation.notification_subject'));
    }

    if (!empty($config->get('confirmation.copy_email_to'))) {
      $this->sendNotificationCopy($bundle, $config->get('confirmation.copy_email_to'), $eid, (int) $member['mid'], $config->get('confirmation.notification_subject'));
    }
  }

  /**
   * Sends an admin copy of the confirmation email, with its own subject.
   */
  protected function sendNotificationCopy(string $bundle, string $recipient, int $eid, int $mid, ?string $subject): void {
    $email = $this->emailSender->build($bundle, $recipient, $eid, [$mid]);
    if (!empty($subject)) {
      $email->setSubject($subject);
    }
    $this->emailSender->sendEmail($email);
  }

}
