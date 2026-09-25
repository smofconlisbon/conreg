<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_discord\Kernel;

use Drupal\conreg_discord\Plugin\Derivative\DiscordMenuDeriver;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore eids

/**
 * Tests the per-event Discord InviteBot admin menu links.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class DiscordMenuDeriverTest extends KernelTestBase {

  /**
   * Modules to enable for this test.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'token',
    'conreg',
    'conreg_discord',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * IDs of the events created for the test.
   *
   * @var int[]
   */
  protected array $eids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('conreg', ['conreg_events']);

    foreach (['First event', 'Second event'] as $name) {
      $this->eids[] = (int) Database::getConnection()->insert('conreg_events')
        ->fields([
          'event_name' => $name,
          'is_open' => 1,
        ])
        ->execute();
    }

    // Menu links are read from the menu_tree table, so rebuild it now the
    // events that the derivers iterate over exist.
    $this->container->get('plugin.manager.menu.link')->rebuild();
  }

  /**
   * The deriver returns one InviteBot link per event.
   */
  public function testDeriverReturnsLinkPerEvent(): void {
    $deriver = DiscordMenuDeriver::create($this->container, 'conreg_discord.event_links');
    $links = $deriver->getDerivativeDefinitions(['id' => 'conreg_discord.event_links']);

    $this->assertCount(count($this->eids), $links);

    foreach ($this->eids as $eid) {
      $link = $links["conreg_discord_$eid"];
      $this->assertSame('conreg_config_discord_invitebot', $link['route_name']);
      // EventStorage returns eid as a string straight from the database.
      $this->assertEquals(['eid' => $eid], $link['route_parameters']);
      $this->assertSame("conreg.event_links:conreg_event_$eid", $link['parent']);
    }
  }

  /**
   * Each InviteBot link's parent resolves to its event's core menu link.
   *
   * The parent ID is a cross-module string contract with conreg's
   * EventsMenuDeriver. Resolving it through the real menu link manager
   * catches either side renaming its plugin or derivative IDs, which would
   * otherwise silently move the link out from under its event.
   */
  public function testParentResolvesToEventMenuLink(): void {
    $manager = $this->container->get('plugin.manager.menu.link');

    foreach ($this->eids as $eid) {
      $link = $manager->getDefinition("conreg_discord.event_links:conreg_discord_$eid");
      $this->assertSame('admin', $link['menu_name']);
      // The menu tree silently empties a parent it can't find.
      $this->assertNotEmpty($link['parent'], "InviteBot link for event $eid lost its parent.");

      $parent = $manager->getDefinition($link['parent']);
      $this->assertSame('conreg_event_overview', $parent['route_name']);
      $this->assertEquals(['eid' => $eid], $parent['route_parameters']);
    }
  }

}
