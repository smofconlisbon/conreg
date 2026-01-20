<?php

namespace Drupal\conreg\Controller;

use Drupal\conreg\ConregConfig;
use Drupal\conreg\Service\MemberStorage;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Mail\MailManagerInterface;

/**
 * Returns responses for ConReg - Simple Convention Registration routes.
 */
class BulkMailController extends ControllerBase {

  /**
   * The controller constructor.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   Mail manager service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected MailManagerInterface $mailManager,
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

    // Set up parameters for receipt email.
    $params = ['eid' => $member['eid'], 'mid' => $member['mid']];
    $params['subject'] = $config->get('bulk_email.template_subject');
    $params['body'] = $config->get('bulk_email.template_body');
    $params['body_format'] = $config->get('bulk_email.template_format');
    $module = "conreg";
    $key = "template";
    $to = $member["email"];
    $language_code = $this->languageManager()->getDefaultLanguage()->getId();

    // Send confirmation email to member.
    if (!empty($member["email"])) {
      $this->mailManager->mail($module, $key, $to, $language_code, $params);
    }

    $content['markup'] = [
      '#markup' => '<p>Bulk send.</p>',
    ];
    return $content;
  }

}
