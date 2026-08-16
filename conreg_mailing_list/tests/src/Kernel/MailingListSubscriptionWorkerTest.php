<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailing_list\Kernel;

use Drupal\conreg\Member;
use Drupal\conreg_mailing_list\Entity\ConregSubscriptionRule;
use Drupal\conreg_mailing_list\Plugin\QueueWorker\MailingListSubscriptionWorker;
use Drupal\conreg_mailing_list_test\FakeProviderCallRecorder;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests MailingListSubscriptionWorker::processItem().
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MailingListSubscriptionWorkerTest extends KernelTestBase {

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
   * Builds a queue item payload for a given rule and member.
   */
  protected function buildItem(ConregSubscriptionRule $rule, Member $member): \stdClass {
    $item = new \stdClass();
    $item->member = $member;
    $item->rule = $rule;
    return $item;
  }

  /**
   * Runs the worker under test on a single item.
   */
  protected function processItem(\stdClass $item): void {
    /** @var \Drupal\Core\Queue\QueueWorkerManagerInterface $manager */
    $manager = $this->container->get('plugin.manager.queue_worker');
    assert($manager instanceof QueueWorkerManagerInterface);
    $manager->createInstance(MailingListSubscriptionWorker::QUEUE_NAME)->processItem($item);
  }

  /**
   * A successful subscribe records the call with the expected details.
   */
  public function testProcessItemCallsProviderSubscribe(): void {
    $rule = ConregSubscriptionRule::create([
      'id' => 'rule_a',
      'label' => 'Rule A',
      'eid' => 1,
      'provider' => 'fake_provider',
      'list_id' => '1',
    ]);
    $member = Member::newMember([
      'eid' => 1,
      'email' => 'member@example.com',
      'first_name' => 'Test',
      'last_name' => 'Member',
    ]);

    $this->processItem($this->buildItem($rule, $member));

    $this->assertCount(1, $this->recorder->calls);
    $this->assertSame('member@example.com', $this->recorder->calls[0]['email']);
    $this->assertSame('1', $this->recorder->calls[0]['listId']);
    $this->assertSame('Test Member', $this->recorder->calls[0]['fields']['name']);
  }

  /**
   * A transient provider failure propagates as a SuspendQueueException.
   */
  public function testProcessItemTransientExceptionSuspendsQueue(): void {
    $this->recorder->subscribeFailure = 'transient';

    $rule = ConregSubscriptionRule::create([
      'id' => 'rule_b',
      'label' => 'Rule B',
      'eid' => 1,
      'provider' => 'fake_provider',
      'list_id' => '1',
    ]);
    $member = Member::newMember([
      'eid' => 1,
      'email' => 'member@example.com',
      'first_name' => 'Test',
      'last_name' => 'Member',
    ]);

    $this->expectException(SuspendQueueException::class);
    $this->processItem($this->buildItem($rule, $member));
  }

  /**
   * A permanent provider failure is logged and dropped, not rethrown.
   */
  public function testProcessItemPermanentExceptionDropsItem(): void {
    $this->recorder->subscribeFailure = 'permanent';

    $rule = ConregSubscriptionRule::create([
      'id' => 'rule_c',
      'label' => 'Rule C',
      'eid' => 1,
      'provider' => 'fake_provider',
      'list_id' => '1',
    ]);
    $member = Member::newMember([
      'eid' => 1,
      'email' => 'member@example.com',
      'first_name' => 'Test',
      'last_name' => 'Member',
    ]);

    $this->processItem($this->buildItem($rule, $member));

    // The provider was called once (and failed), but no exception escaped.
    $this->assertCount(1, $this->recorder->calls);
  }

}
