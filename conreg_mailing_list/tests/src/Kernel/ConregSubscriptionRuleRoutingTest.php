<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailing_list\Kernel;

use Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests routing and link generation for the ConregSubscriptionRule entity.
 *
 * Regression coverage for a bug where EntityBase::urlRouteParameters() never
 * supplied {eid}, so entity-generated links (list builder operations, the
 * edit form's Delete button) silently fell back to the route default eid=1
 * instead of the entity's actual event ID.
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregSubscriptionRuleRoutingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'conreg',
    'conreg_mailing_list',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Route rebuilding is normally deferred to KernelEvents::TERMINATE,
    // which never fires in kernel tests, so path-based matching against the
    // {router} table needs an explicit rebuild here.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Returns the subscription rule entity storage.
   */
  protected function storage(): ConfigEntityStorageInterface {
    return $this->container->get('entity_type.manager')
      ->getStorage('conreg_subscription_rule');
  }

  /**
   * The collection route matches with the event ID as the first segment.
   */
  public function testCollectionRouteMatchesWithEventIdFirst(): void {
    $result = $this->matchPath('/admin/config/conreg/5/subscription-rule');

    $this->assertSame('entity.conreg_subscription_rule.collection', $result['_route']);
    $this->assertSame('5', (string) $result['eid']);
  }

  /**
   * The add-form route matches with the event ID as the first segment.
   */
  public function testAddFormRouteMatches(): void {
    $result = $this->matchPath('/admin/config/conreg/5/subscription-rule/add');

    $this->assertSame('entity.conreg_subscription_rule.add_form', $result['_route']);
    $this->assertSame('5', (string) $result['eid']);
  }

  /**
   * The edit-form route matches with both event ID and rule ID present.
   */
  public function testEditFormRouteMatches(): void {
    $this->createRule('my_rule', 5);

    $result = $this->matchPath('/admin/config/conreg/5/subscription-rule/my_rule/edit');

    $this->assertSame('entity.conreg_subscription_rule.edit_form', $result['_route']);
    $this->assertSame('5', (string) $result['eid']);
    $this->assertSame('my_rule', $result['conreg_subscription_rule']->id());
  }

  /**
   * The delete-form route matches with both event ID and rule ID present.
   */
  public function testDeleteFormRouteMatches(): void {
    $this->createRule('my_rule', 5);

    $result = $this->matchPath('/admin/config/conreg/5/subscription-rule/my_rule/delete');

    $this->assertSame('entity.conreg_subscription_rule.delete_form', $result['_route']);
    $this->assertSame('5', (string) $result['eid']);
    $this->assertSame('my_rule', $result['conreg_subscription_rule']->id());
  }

  /**
   * The sync route matches with both event ID and rule ID present.
   */
  public function testSyncRouteMatches(): void {
    $this->createRule('my_rule', 5);

    $result = $this->matchPath('/admin/config/conreg/5/subscription-rule/my_rule/sync');

    $this->assertSame('entity.conreg_subscription_rule.sync', $result['_route']);
    $this->assertSame('5', (string) $result['eid']);
    $this->assertSame('my_rule', $result['conreg_subscription_rule']->id());
  }

  /**
   * Matches an internal path against the router.
   *
   * The router reads the current path from the path.current service rather
   * than from the path passed to match(), so it must be set explicitly here.
   */
  protected function matchPath(string $path): array {
    $this->container->get('path.current')->setPath($path);
    return $this->container->get('router.no_access_checks')->match($path);
  }

  /**
   * The edit-form URL includes the entity's own event ID, not the default.
   */
  public function testEditFormUrlIncludesEntityOwnEventId(): void {
    $ruleA = $this->createRule('rule_a', 5);
    $ruleB = $this->createRule('rule_b', 9);

    $this->assertStringContainsString('/admin/config/conreg/5/subscription-rule/rule_a/edit', $ruleA->toUrl('edit-form')->toString());
    $this->assertStringContainsString('/admin/config/conreg/9/subscription-rule/rule_b/edit', $ruleB->toUrl('edit-form')->toString());
  }

  /**
   * The delete-form URL includes the entity's own event ID, not the default.
   */
  public function testDeleteFormUrlIncludesEntityOwnEventId(): void {
    $ruleA = $this->createRule('rule_a', 5);
    $ruleB = $this->createRule('rule_b', 9);

    $this->assertStringContainsString('/admin/config/conreg/5/subscription-rule/rule_a/delete', $ruleA->toUrl('delete-form')->toString());
    $this->assertStringContainsString('/admin/config/conreg/9/subscription-rule/rule_b/delete', $ruleB->toUrl('delete-form')->toString());
  }

  /**
   * The edit form's own Delete action button uses the correct event ID.
   */
  public function testEntityFormDeleteActionUsesCorrectEventId(): void {
    $rule = $this->createRule('rule_c', 7);

    $form = $this->container->get('entity.form_builder')->getForm($rule, 'edit');

    $this->assertArrayHasKey('delete', $form['actions']);
    $deleteUrl = $form['actions']['delete']['#url'];
    $this->assertStringContainsString('/admin/config/conreg/7/subscription-rule/rule_c/delete', $deleteUrl->toString());
  }

  /**
   * The collection and add-form routes follow the entity naming convention.
   */
  public function testRouteNamesFollowEntityConvention(): void {
    $routeProvider = $this->container->get('router.route_provider');

    $this->assertNotNull($routeProvider->getRouteByName('entity.conreg_subscription_rule.collection'));
    $this->assertNotNull($routeProvider->getRouteByName('entity.conreg_subscription_rule.add_form'));
  }

  /**
   * Creates and saves a subscription rule for the given event.
   */
  protected function createRule(string $id, int $eid): ConregSubscriptionRuleInterface {
    $rule = $this->storage()->create([
      'id' => $id,
      'label' => $id,
      'eid' => $eid,
    ]);
    $rule->save();
    return $rule;
  }

}
