<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\conreg\Entity\RatePlan;
use Drupal\conreg\Exception\RatePlanApplyException;
use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\RatePlanManager;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests applying rate plans.
 *
 * @group conreg
 */
#[RunTestsInSeparateProcesses]
class RatePlanManagerTest extends KernelTestBase {

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
   * The rate plan manager service.
   */
  protected RatePlanManager $manager;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Kernel tests use a lock backend that never blocks, so use a real one to
    // test locking.
    $container->register('lock', DatabaseLockBackend::class)
      ->addArgument(new Reference('database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'conreg']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('conreg_rate_plan');
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 5]));
    $this->manager = $this->container->get(RatePlanManager::class);
  }

  /**
   * Create and save a rate plan for event 1.
   */
  protected function createPlan(array $prices): RatePlan {
    $plan = RatePlan::create(['eid' => 1, 'planned_date' => '2026-11-01'])->setPrices($prices);
    $plan->save();
    return $plan;
  }

  /**
   * Reload a plan from the database.
   */
  protected function reload(RatePlan $plan): RatePlan {
    return $this->container->get('entity_type.manager')->getStorage('conreg_rate_plan')->loadUnchanged($plan->id());
  }

  /**
   * Tests applying a plan updates prices and records the previous prices.
   */
  public function testApply(): void {
    $plan = $this->createPlan([
      'A' => '60.00',
      'U' => '30.50',
      'C' => '15.00',
      'I' => '0.00',
      'S' => '25.00',
    ]);

    $this->manager->apply($plan);

    $config = $this->config('conreg.settings.1');
    $this->assertSame('60', $config->get('member.types.A.price'));
    $this->assertSame('30.50', $config->get('member.types.U.price'));
    $this->assertSame('0', $config->get('member.types.I.price'));
    // Other member type settings are kept.
    $this->assertSame('Adult', $config->get('member.types.A.name'));
    $this->assertSame('60', $this->container->get(ConregOptions::class)->memberTypes(1)->types['A']->price);

    $plan = $this->reload($plan);
    $this->assertTrue($plan->isApplied());
    $this->assertEquals(5, $plan->get('applied_by')->target_id);
    $previous = $plan->getPreviousPrices();
    $this->assertEquals(50, $previous['A']);
    $this->assertEquals(25, $previous['U']);
  }

  /**
   * Enable Friday and Saturday for Adult, with Saturday given no price.
   */
  protected function enableAdultDays(): void {
    $this->config('conreg.settings.1')
      ->set('member.types.A.days', [
        'Fr' => ['description' => 'Friday only', 'price' => '20'],
        'Sa' => ['description' => 'Saturday only', 'price' => ''],
      ])
      ->save();
    $this->container->get('cache_tags.invalidator')->invalidateTags(['event:1:type']);
  }

  /**
   * Tests applying a plan updates day prices and records the previous ones.
   */
  public function testApplyDayPrices(): void {
    $this->enableAdultDays();
    // Su is a day disabled after the plan was saved.
    $plan = $this->createPlan(['A' => '50', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25'])
      ->setDayPrices(['A' => ['Fr' => '25.00', 'Sa' => '7.50', 'Su' => '9']]);
    $plan->save();

    $this->manager->apply($plan);

    $config = $this->config('conreg.settings.1');
    $this->assertSame('25', $config->get('member.types.A.days.Fr.price'));
    $this->assertSame('7.50', $config->get('member.types.A.days.Sa.price'));
    // Days are never enabled by a plan, and their descriptions are kept.
    $this->assertNull($config->get('member.types.A.days.Su'));
    $this->assertSame('Friday only', $config->get('member.types.A.days.Fr.description'));
    // Main prices are still set.
    $this->assertSame('50', $config->get('member.types.A.price'));

    $plan = $this->reload($plan);
    $this->assertEquals(['A' => ['Fr' => 25, 'Sa' => 7.5]], $plan->getDayPrices());
    $previous = $plan->getPreviousDayPrices();
    $this->assertEquals(20, $previous['A']['Fr']);
    // A day with no price was free, so is recorded as 0.
    $this->assertIsNumeric($previous['A']['Sa']);
    $this->assertEquals(0, $previous['A']['Sa']);
    // Main prices are recorded apart from day prices.
    $this->assertEquals(['A' => 50, 'U' => 25, 'C' => 15, 'I' => 0, 'S' => 25], $plan->getPreviousPrices());
    // Names are recorded as they were, so later renames don't change them.
    $this->assertSame('Adult', $plan->getMemberTypeNames()['A']);
    $this->assertSame(['A' => ['Fr' => 'Friday', 'Sa' => 'Saturday']], $plan->getDayNames());
  }

  /**
   * Tests finding the enabled days a plan has no price for.
   */
  public function testGetMissingDayPrices(): void {
    $this->enableAdultDays();
    // As if Saturday were enabled after the plan was created.
    $plan = $this->createPlan(['A' => '60'])->setDayPrices(['A' => ['Fr' => '20']]);

    $missing = $this->manager->getMissingDayPrices($plan, $this->container->get(ConregOptions::class)->memberTypes(1));
    $this->assertSame(['A' => ['Sa']], array_map('array_keys', $missing));
    $this->assertEquals('Adult, Saturday', (string) $missing['A']['Sa']);
  }

  /**
   * Tests naming days with numeric codes, and names needing escaping.
   */
  public function testDayLabel(): void {
    // Codes that look like numbers are integer array keys.
    $memberTypes = (object) [
      'types' => [
        1 => (object) [
          'name' => 'Kids & Teens',
          'days' => [2 => (object) ['name' => 'Friday']],
        ],
      ],
    ];
    $names = $this->manager->getMemberTypeNames($memberTypes);
    $dayNames = $this->manager->getDayNames($memberTypes);
    $this->assertSame([1 => 'Kids & Teens'], $names);
    $this->assertSame([1 => [2 => 'Friday']], $dayNames);

    $label = $this->manager->dayLabel($names[1], $dayNames[1][2]);
    $this->assertSame('Kids &amp; Teens, Friday', (string) $label);
    // The label is markup, so isn't escaped again when used as a placeholder.
    $this->assertSame('Kids &amp; Teens, Friday: €5.00', (string) new FormattableMarkup('@type: @price', [
      '@type' => $label,
      '@price' => '€5.00',
    ]));
    $this->assertSame('<span class="visually-hidden">Kids &amp; Teens, </span>Friday', (string) $this->manager->dayRowLabel($names[1], $dayNames[1][2]));
  }

  /**
   * Tests day price changes count as changes, and no price counts as free.
   */
  public function testHasDayPriceChanges(): void {
    $this->enableAdultDays();
    $memberTypes = $this->container->get(ConregOptions::class)->memberTypes(1);
    // The current prices, with Saturday's missing price given as 0.
    $plan = $this->createPlan(['A' => '50', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25'])
      ->setDayPrices(['A' => ['Fr' => '20', 'Sa' => '0']]);
    $this->assertFalse($this->manager->hasPriceChanges($plan, $memberTypes));

    $plan->setDayPrices(['A' => ['Fr' => '20', 'Sa' => '5']]);
    $this->assertTrue($this->manager->hasPriceChanges($plan, $memberTypes));
  }

  /**
   * Tests applying a plan drops prices for deleted member types.
   */
  public function testApplyTidiesDeletedMemberTypes(): void {
    // X is a member type deleted after the plan was saved.
    $plan = $this->createPlan(['A' => '60', 'U' => '30', 'C' => '15', 'I' => '0', 'S' => '25', 'X' => '99']);

    $this->manager->apply($plan);

    $this->assertArrayNotHasKey('X', $this->reload($plan)->getPrices());
    $this->assertNull($this->config('conreg.settings.1')->get('member.types.X'));
  }

  /**
   * Tests finding the member types a plan has no price for.
   */
  public function testGetMissingPriceTypes(): void {
    // As if U, C, I and S were added after the plan was created.
    $plan = $this->createPlan(['A' => '60']);

    $this->assertSame(
      ['U' => 'Low Income', 'C' => 'Child', 'I' => 'Infant', 'S' => 'Supporting'],
      $this->manager->getMissingPriceTypes($plan, $this->container->get(ConregOptions::class)->memberTypes(1)),
    );
  }

  /**
   * Tests checking whether a plan would change any prices.
   */
  public function testHasPriceChanges(): void {
    // The current prices, with Adult given as a decimal.
    $plan = $this->createPlan(['A' => '50.00', 'U' => '25', 'C' => '15', 'I' => '0', 'S' => '25']);
    $memberTypes = $this->container->get(ConregOptions::class)->memberTypes(1);
    $this->assertFalse($this->manager->hasPriceChanges($plan, $memberTypes));

    $plan->setPrices(['A' => '60'] + $plan->getPrices())->save();
    $this->assertTrue($this->manager->hasPriceChanges($plan, $memberTypes));
  }

  /**
   * Tests a plan can't be applied twice.
   */
  public function testApplyTwice(): void {
    $plan = $this->createPlan(['A' => '60', 'U' => '30', 'C' => '15', 'I' => '0', 'S' => '25']);
    $this->manager->apply($plan);

    $this->expectException(RatePlanApplyException::class);
    $this->manager->apply($plan);
  }

  /**
   * Tests only one plan per event can be applied at a time.
   */
  public function testApplyWhileEventLocked(): void {
    // A plan whose ID isn't the event ID, so the test can tell the lock is
    // per event rather than per plan.
    $this->createPlan(['A' => '55', 'U' => '30', 'C' => '15', 'I' => '0', 'S' => '25']);
    $plan = $this->createPlan(['A' => '60', 'U' => '30', 'C' => '15', 'I' => '0', 'S' => '25']);
    $this->assertNotEquals($plan->getEventId(), $plan->id());
    // As if another request were applying a different plan for the event.
    $otherRequest = new DatabaseLockBackend(Database::getConnection());
    $this->assertTrue($otherRequest->acquire('conreg_rate_plan_apply:1'));

    try {
      $this->manager->apply($plan);
      $this->fail('Applying a plan while another plan for the event is being applied should fail.');
    }
    catch (RatePlanApplyException $e) {
      $this->assertStringContainsString('is being applied', $e->getMessage());
    }
    $this->assertFalse($this->reload($plan)->isApplied());

    // Plans for other events aren't blocked.
    $otherRequest->releaseAll();
    $this->assertTrue($otherRequest->acquire('conreg_rate_plan_apply:2'));
    $this->manager->apply($plan);
    $this->assertTrue($this->reload($plan)->isApplied());
  }

  /**
   * Tests a plan applied elsewhere isn't applied again from an old copy.
   */
  public function testApplyStalePlan(): void {
    $plan = $this->createPlan(['A' => '60', 'U' => '30', 'C' => '15', 'I' => '0', 'S' => '25']);
    $stale = $this->reload($plan);
    $this->manager->apply($plan);
    $this->assertFalse($stale->isApplied());

    $this->expectException(RatePlanApplyException::class);
    $this->manager->apply($stale);
  }

  /**
   * Tests a plan deleted elsewhere isn't applied from an old copy.
   */
  public function testApplyDeletedPlan(): void {
    $plan = $this->createPlan(['A' => '60', 'U' => '30', 'C' => '15', 'I' => '0', 'S' => '25']);
    $stale = $this->reload($plan);
    $plan->delete();

    try {
      $this->manager->apply($stale);
      $this->fail('Applying a deleted plan should fail.');
    }
    catch (RatePlanApplyException $e) {
      $this->assertStringContainsString('has been deleted', $e->getMessage());
    }
    $this->assertSame('50', $this->config('conreg.settings.1')->get('member.types.A.price'));
  }

  /**
   * Tests applying a plan only changes member type prices in config.
   */
  public function testApplyOnlyChangesPrices(): void {
    // Cache the member types, then change a setting in config without
    // clearing the cache, so the cached member types are out of date.
    $this->container->get(ConregOptions::class)->memberTypes(1);
    $this->config('conreg.settings.1')->set('member.types.A.name', 'Grown-up')->save();
    $before = $this->config('conreg.settings.1')->get('member.types');

    $plan = $this->createPlan(['A' => '60', 'U' => '30', 'C' => '15', 'I' => '0', 'S' => '25']);
    $this->manager->apply($plan);

    // Only the prices changed, and the out of date member types weren't
    // saved over the newer config.
    $after = $this->config('conreg.settings.1')->get('member.types');
    $this->assertSame('Grown-up', $after['A']['name']);
    $this->assertSame('60', $after['A']['price']);
    foreach ($before as $type => $settings) {
      unset($settings['price'], $after[$type]['price']);
      $this->assertSame($settings, $after[$type]);
    }
  }

  /**
   * Tests the price difference uses a true minus sign.
   */
  public function testFormatPriceChange(): void {
    $this->assertSame('+€5.00', $this->manager->formatPriceChange(1, '50', '55'));
    $this->assertSame("\u{2212}€2.50", $this->manager->formatPriceChange(1, '50', '47.50'));
    $this->assertSame('', $this->manager->formatPriceChange(1, '50', '50.00'));
  }

  /**
   * Tests the number of days until a planned date.
   */
  public function testDaysUntil(): void {
    $today = new \DateTimeImmutable('now', $this->manager->getTimezone());

    $this->assertSame(0, $this->manager->daysUntil($today->format('Y-m-d')));
    $this->assertSame(3, $this->manager->daysUntil($today->modify('+3 days')->format('Y-m-d')));
    $this->assertSame(-2, $this->manager->daysUntil($today->modify('-2 days')->format('Y-m-d')));
  }

}
