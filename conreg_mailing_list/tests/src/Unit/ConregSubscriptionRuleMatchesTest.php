<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailing_list\Unit;

use Drupal\conreg\Member;
use Drupal\conreg\MemberOption;
use Drupal\conreg_mailing_list\Entity\ConregSubscriptionRule;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ConregSubscriptionRule::matches() business logic.
 */
#[CoversClass(ConregSubscriptionRule::class)]
#[Group('conreg')]
class ConregSubscriptionRuleMatchesTest extends UnitTestCase {

  /**
   * Builds a subscription rule with the given constraints.
   */
  protected function createRule(string $communicationMethod, int $memberOption): ConregSubscriptionRule {
    return new ConregSubscriptionRule([
      'communication_method' => $communicationMethod,
      'member_option' => $memberOption,
    ], 'conreg_subscription_rule');
  }

  /**
   * Builds a member with the given communication method and options.
   */
  protected function createMember(string $communicationMethod, array $optionIds): Member {
    $member = Member::newMember([
      'communication_method' => $communicationMethod,
    ]);
    $member->options = array_map(
      fn (int $optionId) => new MemberOption(1, $optionId, 1, ''),
      $optionIds
    );
    return $member;
  }

  /**
   * A rule with no constraints matches any member.
   */
  public function testMatchesAnyCommunicationMethodAnyOption(): void {
    $rule = $this->createRule('_any', -1);
    $member = $this->createMember('post', []);

    $this->assertTrue($rule->matches($member));
  }

  /**
   * A matching communication method satisfies the constraint.
   */
  public function testMatchesSpecificCommunicationMethod(): void {
    $rule = $this->createRule('email', -1);
    $member = $this->createMember('email', []);

    $this->assertTrue($rule->matches($member));
  }

  /**
   * A different communication method fails the constraint.
   */
  public function testDoesNotMatchDifferentCommunicationMethod(): void {
    $rule = $this->createRule('email', -1);
    $member = $this->createMember('post', []);

    $this->assertFalse($rule->matches($member));
  }

  /**
   * A member holding the required option satisfies the constraint.
   */
  public function testMatchesSpecificMemberOption(): void {
    $rule = $this->createRule('_any', 5);
    $member = $this->createMember('post', [5]);

    $this->assertTrue($rule->matches($member));
  }

  /**
   * A member missing the required option fails the constraint.
   */
  public function testDoesNotMatchMissingMemberOption(): void {
    $rule = $this->createRule('_any', 5);
    $member = $this->createMember('post', [6]);

    $this->assertFalse($rule->matches($member));
  }

  /**
   * Both constraints must hold: satisfying only one is not a match.
   */
  public function testRequiresBothCommunicationMethodAndOptionToMatch(): void {
    $rule = $this->createRule('email', 5);

    $onlyMethodMatches = $this->createMember('email', [6]);
    $this->assertFalse($rule->matches($onlyMethodMatches));

    $onlyOptionMatches = $this->createMember('post', [5]);
    $this->assertFalse($rule->matches($onlyOptionMatches));

    $bothMatch = $this->createMember('email', [5]);
    $this->assertTrue($rule->matches($bothMatch));
  }

}
