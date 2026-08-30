<?php

namespace Drupal\conreg\Controller;

use Drupal\conreg\ConregConfig;
use Drupal\conreg\Service\ConregEmailSender;
use Drupal\conreg\Service\MemberStorage;
use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for ConReg routes.
 */
class BulkMailController extends ControllerBase {

  /**
   * The controller constructor.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\conreg\Service\ConregEmailSender $emailSender
   *   Builds and sends conreg emails via Easy Email.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected ConregEmailSender $emailSender,
  ) {}

  /**
   * Send an email to a member when triggered by bulk emailer.
   *
   * @param int $mid
   *   The member id.
   *
   * @return array
   *   Content array containing send status.
   */
  public function bulkSend(int $mid): array {
    // Look up email address for member.
    $member = $this->memberStorage->load([
      'mid' => $mid,
      'is_deleted' => 0,
    ]);

    $config = ConregConfig::getConfig($member['eid']);
    $bundle = $config->get('bulk_email.easy_email_type');

    // Send confirmation email to member.
    if (!empty($member["email"])) {
      $this->emailSender->send($bundle, $member['email'], (int) $member['eid'], [(int) $member['mid']], $member['language'] ?? NULL);
    }

    $content['markup'] = [
      '#markup' => '<p>Bulk send.</p>',
    ];
    return $content;
  }

}
