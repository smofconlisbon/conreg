<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailing_list\Kernel;

use Drupal\conreg_mailing_list\MailingListSyncService;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore otherprovider testprovider

/**
 * Tests MailingListSyncService's backfill of existing members.
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MailingListSyncServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'conreg',
    'conreg_mailing_list',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'datetime']);
    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
    ]);
    $this->installConfig(['conreg']);

    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();
  }

  /**
   * The syncRule() method enqueues only matches, skipping invalid emails.
   */
  public function testSyncRuleEnqueuesOnlyMatchingMembers(): void {
    $this->seedMember(['email' => 'matches@example.com', 'communication_method' => 'E']);
    $this->seedMember(['email' => 'nomatch@example.com', 'communication_method' => 'P']);
    $this->seedMember(['email' => 'not-an-email', 'communication_method' => 'E']);

    $rule = $this->createRule('E');

    $count = $this->container->get(MailingListSyncService::class)->syncRule($rule);

    $this->assertSame(1, $count);

    $queue = $this->container->get('queue')->get('conreg_mailing_list_subscription');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame('matches@example.com', $item->data->member->email);
  }

  /**
   * The syncRule() method returns 0 and enqueues nothing when no matches.
   */
  public function testSyncRuleReturnsZeroWhenNothingMatches(): void {
    $this->seedMember(['email' => 'nomatch@example.com', 'communication_method' => 'P']);

    $rule = $this->createRule('E');

    $count = $this->container->get(MailingListSyncService::class)->syncRule($rule);

    $this->assertSame(0, $count);
  }

  /**
   * Inserts a member row for event 1 with the given field overrides.
   */
  protected function seedMember(array $overrides): void {
    $now = \Drupal::time()->getCurrentTime();
    Database::getConnection()->insert('conreg_members')->fields([
      'eid' => 1,
      'lead_mid' => 1,
      'language' => 'en',
      'first_name' => 'Test',
      'last_name' => 'Member',
      'is_paid' => 1,
      'is_deleted' => 0,
      'join_date' => $now,
      'update_date' => $now,
    ] + $overrides)->execute();
  }

  /**
   * Creates and saves a rule for event 1, constrained by communication method.
   */
  protected function createRule(string $communicationMethod) {
    $rule = $this->container->get('entity_type.manager')
      ->getStorage('conreg_subscription_rule')
      ->create([
        'id' => 'sync_test_rule',
        'label' => 'Sync Test Rule',
        'eid' => 1,
        'provider' => 'testprovider',
        'list_id' => '1',
        'communication_method' => $communicationMethod,
      ]);
    $rule->save();
    return $rule;
  }

}
