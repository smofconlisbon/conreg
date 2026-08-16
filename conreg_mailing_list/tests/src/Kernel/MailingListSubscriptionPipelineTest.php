<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailing_list\Kernel;

use Drupal\conreg\Member;
use Drupal\conreg\MemberOption;
use Drupal\conreg_mailing_list\Hook\ConregMailingListHooks;
use Drupal\conreg_mailing_list\Plugin\QueueWorker\MailingListSubscriptionWorker;
use Drupal\conreg_mailing_list_test\FakeProviderCallRecorder;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the full member-added -> enqueue -> worker pipeline.
 *
 * Regression coverage for a bug where ConregMailingListHooks enqueued to a
 * queue name that did not match MailingListSubscriptionWorker's plugin ID,
 * so enqueued items were silently never processed.
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MailingListSubscriptionPipelineTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'conreg',
    'conreg_mailing_list',
    'conreg_mailing_list_test',
  ];

  /**
   * The fake provider's call recorder.
   */
  protected FakeProviderCallRecorder $recorder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->recorder = $this->container->get(FakeProviderCallRecorder::class);
  }

  /**
   * A matching member is enqueued and reaches the provider when processed.
   *
   * The item is enqueued under the worker's own queue name, and processing
   * that queue actually reaches the provider.
   */
  public function testMemberAddedEnqueuesUnderWorkerQueueAndIsProcessed(): void {
    $rule = \Drupal::entityTypeManager()
      ->getStorage('conreg_subscription_rule')
      ->create([
        'id' => 'rule_pipeline',
        'label' => 'Pipeline Rule',
        'eid' => 1,
        'provider' => 'fake_provider',
        'list_id' => '1',
      ]);
    $rule->save();

    $member = Member::newMember([
      'eid' => 1,
      'email' => 'member@example.com',
      'first_name' => 'Test',
      'last_name' => 'Member',
      'options' => [],
    ]);

    $this->container->get(ConregMailingListHooks::class)->memberAdded($member);

    $queue = $this->container->get('queue')->get(MailingListSubscriptionWorker::QUEUE_NAME);
    $this->assertSame(1, $queue->numberOfItems(), 'Item enqueued under the worker plugin ID.');

    $manager = $this->container->get('plugin.manager.queue_worker');
    $worker = $manager->createInstance(MailingListSubscriptionWorker::QUEUE_NAME);
    $item = $queue->claimItem();
    $worker->processItem($item->data);
    $queue->deleteItem($item);

    $this->assertCount(1, $this->recorder->calls);
    $this->assertSame('member@example.com', $this->recorder->calls[0]['email']);
  }

  /**
   * A member whose email is invalid is not enqueued at all.
   */
  public function testMemberAddedSkipsInvalidEmail(): void {
    $rule = \Drupal::entityTypeManager()
      ->getStorage('conreg_subscription_rule')
      ->create([
        'id' => 'rule_invalid_email',
        'label' => 'Invalid Email Rule',
        'eid' => 1,
        'provider' => 'fake_provider',
        'list_id' => '1',
      ]);
    $rule->save();

    $member = Member::newMember([
      'eid' => 1,
      'email' => 'not-an-email',
      'first_name' => 'Test',
      'last_name' => 'Member',
      'options' => [],
    ]);

    $this->container->get(ConregMailingListHooks::class)->memberAdded($member);

    $queue = $this->container->get('queue')->get(MailingListSubscriptionWorker::QUEUE_NAME);
    $this->assertSame(0, $queue->numberOfItems());
  }

  /**
   * Matching requires all constraints; satisfying only one isn't enough.
   *
   * Regression test for a bug where the hook combined the communication
   * method and member option checks with OR instead of AND.
   */
  public function testMemberAddedRequiresAllConstraintsToMatch(): void {
    $rule = \Drupal::entityTypeManager()
      ->getStorage('conreg_subscription_rule')
      ->create([
        'id' => 'rule_and_semantics',
        'label' => 'AND Semantics Rule',
        'eid' => 1,
        'provider' => 'fake_provider',
        'list_id' => '1',
        'communication_method' => 'email',
        'member_option' => 5,
      ]);
    $rule->save();

    $queue = $this->container->get('queue')->get(MailingListSubscriptionWorker::QUEUE_NAME);
    $hooks = $this->container->get(ConregMailingListHooks::class);

    $hooks->memberAdded(Member::newMember([
      'eid' => 1,
      'email' => 'comms-only@example.com',
      'first_name' => 'Comms',
      'last_name' => 'Only',
      'communication_method' => 'email',
      'options' => [],
    ]));
    $this->assertSame(0, $queue->numberOfItems(), 'Communication-method-only match is not enough.');

    $hooks->memberAdded(Member::newMember([
      'eid' => 1,
      'email' => 'option-only@example.com',
      'first_name' => 'Option',
      'last_name' => 'Only',
      'communication_method' => 'post',
      'options' => [new MemberOption(1, 5, 1, '')],
    ]));
    $this->assertSame(0, $queue->numberOfItems(), 'Member-option-only match is not enough.');

    $hooks->memberAdded(Member::newMember([
      'eid' => 1,
      'email' => 'both@example.com',
      'first_name' => 'Both',
      'last_name' => 'Match',
      'communication_method' => 'email',
      'options' => [new MemberOption(1, 5, 1, '')],
    ]));
    $this->assertSame(1, $queue->numberOfItems(), 'Matching both constraints enqueues.');
  }

  /**
   * A disabled rule does not enqueue members, even if it otherwise matches.
   */
  public function testMemberAddedSkipsDisabledRule(): void {
    $disabledRule = \Drupal::entityTypeManager()
      ->getStorage('conreg_subscription_rule')
      ->create([
        'id' => 'rule_disabled',
        'label' => 'Disabled Rule',
        'eid' => 1,
        'provider' => 'fake_provider',
        'list_id' => '1',
        'status' => FALSE,
      ]);
    $disabledRule->save();

    $queue = $this->container->get('queue')->get(MailingListSubscriptionWorker::QUEUE_NAME);
    $hooks = $this->container->get(ConregMailingListHooks::class);

    $hooks->memberAdded(Member::newMember([
      'eid' => 1,
      'email' => 'disabled-rule@example.com',
      'first_name' => 'Test',
      'last_name' => 'Member',
      'options' => [],
    ]));
    $this->assertSame(0, $queue->numberOfItems(), 'Disabled rule must not enqueue a match.');

    $disabledRule->enable()->save();

    $hooks->memberAdded(Member::newMember([
      'eid' => 1,
      'email' => 'enabled-rule@example.com',
      'first_name' => 'Test',
      'last_name' => 'Member',
      'options' => [],
    ]));
    $this->assertSame(1, $queue->numberOfItems(), 'Enabling the same rule allows it to enqueue a match.');
  }

}
