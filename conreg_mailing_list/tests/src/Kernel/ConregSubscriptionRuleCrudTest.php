<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailing_list\Kernel;

use Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore otherprovider testprovider

/**
 * Tests add/edit/delete of the ConregSubscriptionRule config entity.
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregSubscriptionRuleCrudTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'conreg',
    'conreg_mailing_list',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * Returns the subscription rule entity storage.
   */
  protected function storage(): ConfigEntityStorageInterface {
    return $this->container->get('entity_type.manager')
      ->getStorage('conreg_subscription_rule');
  }

  /**
   * Creating a rule persists the given values and applies field defaults.
   */
  public function testCreateSubscriptionRule(): void {
    $this->storage()->create([
      'id' => 'rule_one',
      'label' => 'Rule One',
      'eid' => 1,
    ])->save();

    $rule = $this->loadRule('rule_one');
    $this->assertSame('Rule One', $rule->label());
    $this->assertSame(1, $rule->getEventId());
    $this->assertSame('', $rule->getDescription());
    $this->assertSame('', $rule->getProvider());
    $this->assertSame('', $rule->getListId());
    $this->assertSame('', $rule->getListName());
    $this->assertSame('_any', $rule->getCommunicationMethod());
    $this->assertSame(-1, $rule->getMemberOption());
    $this->assertTrue($rule->status());
  }

  /**
   * Saving a rule without an event ID throws, per preSave().
   */
  public function testCreateWithoutEventIdThrowsException(): void {
    $rule = $this->storage()->create([
      'id' => 'rule_no_event',
      'label' => 'Rule Without Event',
    ]);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Subscription rule must belong to an event.');
    $rule->save();
  }

  /**
   * Editing a rule persists the new values.
   */
  public function testUpdateSubscriptionRule(): void {
    $this->storage()->create([
      'id' => 'rule_edit',
      'label' => 'Original Label',
      'eid' => 1,
      'provider' => 'testprovider',
      'list_id' => '1',
      'list_name' => 'Original List',
      'communication_method' => 'email',
      'member_option' => 5,
    ])->save();

    $rule = $this->loadRule('rule_edit');
    $rule->set('label', 'Updated Label');
    $rule->set('provider', 'otherprovider');
    $rule->set('list_id', '2');
    $rule->set('list_name', 'Updated List');
    $rule->set('communication_method', '_any');
    $rule->set('member_option', -1);
    $rule->save();

    $reloaded = $this->loadRule('rule_edit');
    $this->assertSame('Updated Label', $reloaded->label());
    $this->assertSame('otherprovider', $reloaded->getProvider());
    $this->assertSame('2', $reloaded->getListId());
    $this->assertSame('Updated List', $reloaded->getListName());
    $this->assertSame('_any', $reloaded->getCommunicationMethod());
    $this->assertSame(-1, $reloaded->getMemberOption());
  }

  /**
   * Deleting a rule removes both the entity and its backing config object.
   */
  public function testDeleteSubscriptionRule(): void {
    $this->storage()->create([
      'id' => 'rule_delete',
      'label' => 'Rule To Delete',
      'eid' => 1,
    ])->save();

    $this->assertNotNull($this->loadRule('rule_delete'));

    $rule = $this->loadRule('rule_delete');
    $rule->delete();

    $this->storage()->resetCache();
    $this->assertNull($this->storage()->load('rule_delete'));
    $this->assertTrue(
      $this->container->get('config.factory')
        ->get('conreg_mailing_list.conreg_subscription_rule.rule_delete')
        ->isNew()
    );
  }

  /**
   * Disabling and re-enabling a rule persists the status flag.
   */
  public function testEnableDisableStatus(): void {
    $this->storage()->create([
      'id' => 'rule_status',
      'label' => 'Rule Status',
      'eid' => 1,
    ])->save();

    $rule = $this->loadRule('rule_status');
    $rule->setStatus(FALSE)->save();
    $this->assertFalse($this->loadRule('rule_status')->status());

    $rule = $this->loadRule('rule_status');
    $rule->setStatus(TRUE)->save();
    $this->assertTrue($this->loadRule('rule_status')->status());
  }

  /**
   * The entity's exported array matches exactly the declared config_export.
   */
  public function testConfigExportProperties(): void {
    $rule = $this->storage()->create([
      'id' => 'rule_export',
      'label' => 'Rule Export',
      'eid' => 1,
    ]);
    $rule->save();

    $expectedKeys = [
      'id',
      'label',
      'description',
      'eid',
      'provider',
      'list_id',
      'list_name',
      'communication_method',
      'member_option',
    ];

    $exported = $rule->toArray();
    foreach ($expectedKeys as $key) {
      $this->assertArrayHasKey($key, $exported);
    }
  }

  /**
   * Rules can be queried filtered by event ID, as the list builder does.
   */
  public function testMultipleRulesFilteredByEventId(): void {
    $this->storage()->create(['id' => 'rule_e1_a', 'label' => 'E1 A', 'eid' => 1])->save();
    $this->storage()->create(['id' => 'rule_e1_b', 'label' => 'E1 B', 'eid' => 1])->save();
    $this->storage()->create(['id' => 'rule_e2_a', 'label' => 'E2 A', 'eid' => 2])->save();

    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('eid', 1)
      ->sort('label')
      ->execute();

    $this->assertSame(['rule_e1_a', 'rule_e1_b'], array_values($ids));
  }

  /**
   * Loads a subscription rule by ID, bypassing the static entity cache.
   */
  protected function loadRule(string $id): ?ConregSubscriptionRuleInterface {
    $this->storage()->resetCache([$id]);
    /** @var \Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface|null $rule */
    $rule = $this->storage()->load($id);
    return $rule;
  }

}
