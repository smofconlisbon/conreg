<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Service\UpgradeStorage;
use Drupal\conreg\UpgradeManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests UpgradeManager::loadUpgrades().
 *
 * Reproduces issue #3596643: loadUpgrades() reads the lead member ID off
 * the list of loaded upgrade rows (`$upgrades['lead_mid']`) instead of off
 * an individual row, which doesn't exist at that level and raises an
 * "Undefined array key" warning - which fails the test because
 * phpunit.xml.dist sets failOnWarning="true" (see
 * tests/src/Unit/Form/Admin/MemberClassesTest.php for the same technique
 * used against a similar bug).
 */
#[Group('conreg')]
class UpgradeManagerTest extends UnitTestCase {

  /**
   * Loading more than one upgrade for a lead member must not warn.
   */
  public function testLoadUpgradesForLeadMemberWithMultipleUpgradesDoesNotWarn(): void {
    $upgradeStorage = $this->createMock(UpgradeStorage::class);
    $upgradeStorage->method('loadAll')->willReturn([
      [
        'mid' => 11,
        'lead_mid' => 10,
        'from_type' => 'A',
        'from_days' => '',
        'to_type' => 'B',
        'to_days' => '',
        'to_badge_type' => 'B',
        'upgrade_price' => 10.0,
      ],
      [
        'mid' => 12,
        'lead_mid' => 10,
        'from_type' => 'A',
        'from_days' => '',
        'to_type' => 'B',
        'to_days' => '',
        'to_badge_type' => 'B',
        'upgrade_price' => 5.0,
      ],
    ]);

    // The lead member ID is already known from the loaded upgrade rows, so
    // Upgrade::getLead() should never need to fall back to a member lookup.
    $memberStorage = $this->createMock(MemberStorage::class);
    $memberStorage->expects($this->never())->method('load');

    $manager = new UpgradeManager(
      $upgradeStorage,
      $memberStorage,
      $this->createMock(TimeInterface::class),
      $this->createMock(ConregOptions::class),
      1,
    );

    $manager->loadUpgrades(10, 0);

    $this->assertSame(10, $manager->leadMid);
    $this->assertSame(2, $manager->count());
  }

}
