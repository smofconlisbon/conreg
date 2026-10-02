<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Entity\RatePlan;
use Drupal\conreg\RatePlanStorage;
use Drupal\conreg\Service\EventStorage;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the rate plan entity and its storage.
 *
 * @group conreg
 */
#[RunTestsInSeparateProcesses]
class RatePlanStorageTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('conreg_rate_plan');
    // Building the permission list includes conreg's per-event permissions.
    $this->installSchema('conreg', ['conreg_events']);
  }

  /**
   * Gets the rate plan storage.
   */
  protected function storage(): RatePlanStorage {
    return $this->container->get('entity_type.manager')->getStorage('conreg_rate_plan');
  }

  /**
   * Create and save a rate plan.
   */
  protected function createPlan(int $eid, string $plannedDate, array $prices): RatePlan {
    $plan = RatePlan::create(['eid' => $eid, 'planned_date' => $plannedDate])->setPrices($prices);
    $plan->save();
    return $plan;
  }

  /**
   * Tests saving, loading and updating a plan's prices.
   */
  public function testPrices(): void {
    $plan = $this->createPlan(1, '2026-11-01', ['A' => '60.00']);

    $plan = $this->storage()->loadUnchanged($plan->id());
    $this->assertSame(1, $plan->getEventId());
    $this->assertSame('2026-11-01', $plan->getPlannedDate());
    $this->assertFalse($plan->isApplied());
    $this->assertNull($plan->getAppliedTime());
    $this->assertEquals(['A' => 60], $plan->getPrices());
    $this->assertSame(['A' => NULL], $plan->getPreviousPrices());

    // Setting prices replaces the existing ones.
    $plan->setPrices(['C' => '15.50'])->save();
    $plan = $this->storage()->loadUnchanged($plan->id());
    $this->assertEquals(['C' => 15.5], $plan->getPrices());

    $plan->setPrices([])->save();
    $this->assertSame([], $this->storage()->loadUnchanged($plan->id())->getPrices());
  }

  /**
   * Tests day prices are kept apart from main prices.
   */
  public function testDayPrices(): void {
    $plan = $this->createPlan(1, '2026-11-01', ['A' => '60'])
      ->setDayPrices(['A' => ['Fr' => '20', 'Sa' => '25.50']]);
    $plan->save();

    $plan = $this->storage()->loadUnchanged($plan->id());
    $this->assertEquals(['A' => 60], $plan->getPrices());
    $this->assertEquals(['A' => ['Fr' => 20, 'Sa' => 25.5]], $plan->getDayPrices());
    $this->assertSame(['A' => ['Fr' => NULL, 'Sa' => NULL]], $plan->getPreviousDayPrices());

    // Setting either kind of price keeps the other.
    $plan->setPrices(['A' => '65'])->save();
    $plan = $this->storage()->loadUnchanged($plan->id());
    $this->assertEquals(['A' => ['Fr' => 20, 'Sa' => 25.5]], $plan->getDayPrices());
    $plan->setDayPrices(['A' => ['Fr' => '22']])->save();
    $plan = $this->storage()->loadUnchanged($plan->id());
    $this->assertEquals(['A' => 65], $plan->getPrices());
    $this->assertEquals(['A' => ['Fr' => 22]], $plan->getDayPrices());

    $plan->markApplied(['A' => '60'], ['A' => ['Fr' => '20']], 5, 200)->save();
    $plan = $this->storage()->loadUnchanged($plan->id());
    $this->assertEquals(['A' => 60], $plan->getPreviousPrices());
    $this->assertEquals(['A' => ['Fr' => 20]], $plan->getPreviousDayPrices());
  }

  /**
   * Tests listing planned and applied plans.
   */
  public function testLoadPlannedAndApplied(): void {
    $late = $this->createPlan(1, '2026-12-01', ['A' => '70']);
    $early = $this->createPlan(1, '2026-11-01', ['A' => '60']);
    $this->createPlan(2, '2026-10-01', ['A' => '50']);

    $this->assertEquals([$early->id(), $late->id()], array_keys($this->storage()->loadPlanned(1)));
    $this->assertSame([], $this->storage()->appliedQuery(1)->execute());

    $early->markApplied(['A' => '50'], [], 5, 200)->save();

    $this->assertEquals([$late->id()], array_keys($this->storage()->loadPlanned(1)));
    $applied = $this->storage()->loadMultiple($this->storage()->appliedQuery(1)->execute());
    $this->assertEquals([$early->id()], array_keys($applied));
    $applied = reset($applied);
    $this->assertTrue($applied->isApplied());
    $this->assertSame(200, $applied->getAppliedTime());
    $this->assertEquals(5, $applied->get('applied_by')->target_id);
    $this->assertEquals(['A' => 50], $applied->getPreviousPrices());
  }

  /**
   * Tests counting the unapplied plans before a plan.
   */
  public function testCountPlannedBefore(): void {
    $applied = $this->createPlan(1, '2026-09-01', ['A' => '50']);
    $applied->markApplied(['A' => '40'], [], 5, 200)->save();
    $early = $this->createPlan(1, '2026-10-01', ['A' => '55']);
    $plan = $this->createPlan(1, '2026-11-01', ['A' => '60']);
    // Plans on the same date are listed by ID.
    $sameDate = $this->createPlan(1, '2026-11-01', ['A' => '65']);
    $this->createPlan(1, '2026-12-01', ['A' => '70']);
    $this->createPlan(2, '2026-10-01', ['A' => '50']);

    $this->assertSame(0, $this->storage()->countPlannedBefore($early));
    $this->assertSame(1, $this->storage()->countPlannedBefore($plan));
    $this->assertSame(2, $this->storage()->countPlannedBefore($sameDate));
  }

  /**
   * Tests applied plans can't be edited, applied again or deleted.
   */
  public function testAppliedPlanAccess(): void {
    $this->installConfig(['conreg']);
    $this->setUpCurrentUser(permissions: ['configure convention registration']);
    $plan = $this->createPlan(1, '2026-11-01', ['A' => '60', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25']);

    foreach (['update', 'delete', 'apply'] as $operation) {
      $this->assertTrue($plan->access($operation), $operation);
    }

    $plan->markApplied(['A' => '50'], [], 5, 200)->save();
    // Access results are cached for the rest of the request.
    $this->container->get('entity_type.manager')->getAccessControlHandler('conreg_rate_plan')->resetCache();

    foreach (['update', 'delete', 'apply'] as $operation) {
      $this->assertFalse($plan->access($operation), $operation);
    }
  }

  /**
   * Tests plans missing prices, or that change nothing, can't be applied.
   */
  public function testApplyAccess(): void {
    $this->installConfig(['conreg']);
    $this->setUpCurrentUser(permissions: ['configure convention registration']);
    $accessHandler = $this->container->get('entity_type.manager')->getAccessControlHandler('conreg_rate_plan');

    // As if U, C, I and S were added after the plan was created.
    $missing = $this->createPlan(1, '2026-11-01', ['A' => '60']);
    $access = $missing->access('apply', return_as_object: TRUE);
    $this->assertTrue($access->isForbidden());
    $this->assertContains('config:conreg.settings.1', $access->getCacheTags());
    // The plan can still be edited to add the missing prices.
    $this->assertTrue($missing->access('update'));

    // The current prices.
    $unchanged = $this->createPlan(1, '2026-11-01', ['A' => '50', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25']);
    $this->assertTrue($unchanged->access('apply', return_as_object: TRUE)->isForbidden());

    $unchanged->setPrices(['A' => '60'] + $unchanged->getPrices())->save();
    $accessHandler->resetCache();
    $this->assertTrue($unchanged->access('apply'));
  }

  /**
   * Tests plans missing a price for an enabled day can't be applied.
   */
  public function testApplyAccessMissingDayPrice(): void {
    $this->installConfig(['conreg']);
    $this->config('conreg.settings.1')
      ->set('member.types.A.days', ['Fr' => ['description' => 'Friday', 'price' => '20']])
      ->save();
    $this->setUpCurrentUser(permissions: ['configure convention registration']);

    // As if Friday were enabled after the plan was created.
    $plan = $this->createPlan(1, '2026-11-01', ['A' => '60', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25']);
    $this->assertTrue($plan->access('apply', return_as_object: TRUE)->isForbidden());

    $plan->setDayPrices(['A' => ['Fr' => '20']])->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('conreg_rate_plan')->resetCache();
    $this->assertTrue($plan->access('apply'));
  }

  /**
   * Tests deleting an event deletes its plans, including applied ones.
   */
  public function testDeleteEventDeletesPlans(): void {
    $connection = Database::getConnection();
    $eid = (int) $connection->insert('conreg_events')->fields(['event_name' => 'Deleted event'])->execute();
    $otherEid = (int) $connection->insert('conreg_events')->fields(['event_name' => 'Other event'])->execute();
    $this->createPlan($eid, '2026-11-01', ['A' => '60']);
    $this->createPlan($eid, '2026-10-01', ['A' => '55'])->markApplied(['A' => '50'], [], 5, 200)->save();
    $kept = $this->createPlan($otherEid, '2026-11-01', ['A' => '60']);

    $this->container->get(EventStorage::class)->delete(['eid' => $eid]);

    $this->storage()->resetCache();
    $this->assertEquals([$kept->id()], array_keys($this->storage()->loadMultiple()));
  }

  /**
   * Tests rate plan URLs include the event.
   */
  public function testUrls(): void {
    $plan = $this->createPlan(3, '2026-11-01', ['A' => '60']);

    $this->assertSame("/admin/config/conreg/rate-plans/3/{$plan->id()}/apply", $plan->toUrl('apply-form')->toString());
    $this->assertSame('/admin/config/conreg/rate-plans/3', $plan->toUrl('collection')->toString());
  }

}
