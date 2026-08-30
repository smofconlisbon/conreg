<?php

namespace Drupal\conreg_planz;

use Drupal\Component\Utility\DeprecationHelper;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\conreg\Member;
use Drupal\conreg\Service\ConregEmailSender;

// cspell:ignore permroleid permrolename

/**
 * Class for adding members to PlanZ.
 */
class PlanZ {

  /**
   * Target database connection key.
   *
   * @var string
   */
  public readonly string $target;

  /**
   * Source of badge ID.
   *
   * @var \Drupal\conreg_planz\BadgeIdSource
   */
  public readonly BadgeIdSource $badgeIdSource;

  /**
   * Badge ID prefix.
   *
   * @var string
   */
  public readonly string $prefix;

  /**
   * Number of digits to pad badge ID.
   *
   * @var int
   */
  public readonly int $digits;

  /**
   * Whether to generate a password automatically.
   *
   * @var bool
   */
  public readonly bool $generatePassword;

  /**
   * Roles to assign to PlanZ user.
   *
   * @var array
   */
  public readonly array $roles;

  /**
   * Default value for interested field.
   *
   * @var bool
   */
  public readonly bool $interestedDefault;

  /**
   * Base URL for PlanZ site.
   *
   * @var string
   */
  public readonly string $planZUrl;

  /**
   * Option fields to copy over.
   *
   * @var array
   */
  public readonly array $optionFields;

  /**
   * Whether PlanZ integration is auto-enabled.
   *
   * @var bool
   */
  public readonly bool $autoEnabled;

  /**
   * Whether auto-enable occurs when confirmed.
   *
   * @var bool
   */
  public readonly bool $autoWhenConfirmed;

  /**
   * The Easy Email template used for the invite email.
   *
   * @var string
   */
  public readonly string $emailEasyEmailType;

  /**
   * Constructs a new Member object.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object containing PlanZ settings.
   */
  public function __construct(ImmutableConfig|NULL $config = NULL) {
    $this->target = $config->get('target') ?: 'default';
    $this->badgeIdSource = BadgeIdSource::from($config->get('badge_id_source') ?: 'mno');
    $this->prefix = $config->get('prefix') ?: '';
    $this->digits = $config->get('digits') ?: 4;
    $this->generatePassword = $config->get('generate_password') ?: FALSE;
    $this->roles = $config->get('roles') ?: [];
    $this->interestedDefault = $config->get('interested_default') ?: FALSE;
    $this->planZUrl = $config->get('url') ?: '';
    $this->optionFields = $config->get('option_fields') ?? [];
    $this->autoEnabled = $config->get('auto.enabled') ?: FALSE;
    $this->autoWhenConfirmed = $config->get('auto.when_confirmed') ?: FALSE;
    $this->emailEasyEmailType = $config->get('email.easy_email_type') ?: '';
  }

  /**
   * Get the connection to the PlanZ database.
   *
   * @return \Drupal\Core\Database\Connection
   *   The database connection object for PlanZ.
   */
  public function getConnection(): Connection {
    return Database::getConnection($this->target, 'planz');
  }

  /**
   * Test the connection and check number of records in CongoDump table.
   *
   * @param int &$count
   *   Count of members.
   *
   * @return bool|null
   *   TRUE if the connection is valid and table exists.
   *   FALSE if the connection exists but table not found.
   *   NULL if the connection could not be established.
   */
  public function test(int &$count) {
    try {
      $con = $this->getConnection();
      $count = $con->select('CongoDump', 'C')
        ->fields('C')
        ->countQuery()
        ->execute()
        ->fetchField();
      $result = TRUE;
    }
    catch (ConnectionNotDefinedException $e) {
      \Drupal::logger('type')->error($e->getMessage());
      $result = FALSE;
    }
    catch (\PDOException $e) {
      \Drupal::logger('type')->error($e->getMessage());
      $result = NULL;
    }
    return $result;
  }

  /**
   * Get the permission roles present on PlanZ.
   *
   * @return array
   *   The array of permission roles
   */
  public function getPermissionRoles(): array {
    $con = $this->getConnection();
    $select = $con->select('PermissionRoles', 'P');
    $select->addField('P', 'permroleid');
    $select->addField('P', 'permrolename');
    $select->orderBy('P.display_order');
    return DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.2.0', fn() => $select->execute()->fetchAll(FetchAs::Associative), fn() => $select->execute()->fetchAll(\PDO::FETCH_ASSOC));
  }

  /**
   * Create PlanZ badge ID for member.
   *
   * @param \Drupal\conreg\Member $member
   *   The member object to generate the badge ID for.
   *
   * @return string
   *   The generated badge ID string.
   */
  public function createBadgeId(Member $member): string {
    $memberRef = match($this->badgeIdSource) {
      BadgeIdSource::MemberID => $member->mid,
      BadgeIdSource::MemberNumber => $member->member_no,
      default => $member->mid
    };
    $badgeId = $this->prefix . str_pad($memberRef, $this->digits, "0", STR_PAD_LEFT);
    return $badgeId;
  }

  /**
   * Send email invite to new PlanZ user.
   *
   * @param PlanZUser $user
   *   The PlanZ user to send invite to.
   */
  public function sendInviteEmail(PlanZUser $user) {
    // Look up member to get email.
    $member = Member::loadMember($user->mid);

    if (empty($member->email)) {
      return FALSE;
    }

    $planzTokenData = [
      'user' => $user->badgeId,
      'url' => $this->planZUrl,
    ];
    if (isset($user->password)) {
      $planzTokenData['password'] = $user->password;
    }

    return \Drupal::service(ConregEmailSender::class)->send(
      $this->emailEasyEmailType,
      $member->email,
      $member->eid,
      [$user->mid],
      $member->language ?? NULL,
      ['planz' => $planzTokenData],
    );
  }

}
