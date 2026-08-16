<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list;

use Drupal\conreg\Member;
use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface defining a subscription rule entity type.
 */
interface ConregSubscriptionRuleInterface extends ConfigEntityInterface {

  /**
   * Get the rule description.
   */
  public function getDescription(): string;

  /**
   * Set the event ID.
   */
  public function setEventId(int $eid): self;

  /**
   * Get the event ID.
   */
  public function getEventId(): int;

  /**
   * Get the mailing list provider plugin.
   */
  public function getProvider(): string;

  /**
   * Get the mailing list ID.
   */
  public function getListId(): string;

  /**
   * Get the mailing list name.
   */
  public function getListName(): string;

  /**
   * Get the rule's communication method.
   */
  public function getCommunicationMethod(): string;

  /**
   * Get the rule's member option ID.
   */
  public function getMemberOption(): int;

  /**
   * Check if member should be added to mailing list.
   *
   * @param \Drupal\conreg\Member $member
   *   The member to check.
   *
   * @return bool
   *   Returns true if member matches
   */
  public function matches(Member $member): bool;

}
