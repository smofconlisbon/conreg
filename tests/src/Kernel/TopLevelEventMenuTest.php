<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Service\EventStorage;
use Drupal\Core\Database\Database;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests moving a lone or sole open event's menu links directly under ConReg.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class TopLevelEventMenuTest extends KernelTestBase {

  /**
   * Modules to enable for this test.
   *
   * The Discord submodule is included to check that links added by
   * submodules move along with the core event links.
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
   * The event storage service.
   */
  protected EventStorage $eventStorage;

  /**
   * The menu link manager.
   */
  protected MenuLinkManagerInterface $menuLinkManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('conreg', ['conreg_events']);

    $this->eventStorage = $this->container->get(EventStorage::class);
    $this->menuLinkManager = $this->container->get('plugin.manager.menu.link');
  }

  /**
   * With no events, no event is chosen for the top level.
   */
  public function testNoEvents(): void {
    $this->assertNull($this->eventStorage->getTopLevelMenuEventId());
  }

  /**
   * A single event is moved to the top level even when it is closed.
   */
  public function testSingleClosedEventIsFlattened(): void {
    $eid = $this->createEvent('Only event', FALSE);
    $this->menuLinkManager->rebuild();

    $this->assertSame($eid, $this->eventStorage->getTopLevelMenuEventId());
    $this->assertFlattened($eid);
  }

  /**
   * The only open event is moved to the top level; closed events aren't.
   */
  public function testSoleOpenEventIsFlattened(): void {
    $openEid = $this->createEvent('Open event', TRUE);
    $closedEid = $this->createEvent('Closed event', FALSE);
    $this->menuLinkManager->rebuild();

    $this->assertSame($openEid, $this->eventStorage->getTopLevelMenuEventId());
    $this->assertFlattened($openEid);
    $this->assertNested($closedEid);
  }

  /**
   * Other events' submenus come last, keeping their relative order.
   */
  public function testOtherEventsSortLast(): void {
    $openEid = $this->createEvent('Open event', TRUE);
    $firstClosed = $this->createEvent('First closed event', FALSE);
    $secondClosed = $this->createEvent('Second closed event', FALSE);
    $this->menuLinkManager->rebuild();

    $firstWeight = $this->menuLinkManager->getDefinition("conreg.event_links:conreg_event_$firstClosed")['weight'];
    $secondWeight = $this->menuLinkManager->getDefinition("conreg.event_links:conreg_event_$secondClosed")['weight'];

    $maxOtherWeight = max(array_map(
      fn (string $id): int => (int) $this->menuLinkManager->getDefinition($id)['weight'],
      array_keys(array_filter(
        $this->menuLinkManager->getDefinitions(),
        fn (array $link, string $id): bool => $link['parent'] === 'conreg.overview'
          && !str_starts_with($id, 'conreg.event_links:conreg_event_'),
        ARRAY_FILTER_USE_BOTH,
      )),
    ));

    $this->assertGreaterThan($maxOtherWeight, min($firstWeight, $secondWeight));
    // The deriver gives earlier events higher weights; that order is kept.
    $this->assertGreaterThan($secondWeight, $firstWeight);
    $this->assertFlattened($openEid);
  }

  /**
   * A flattened event's links interleave with the top-level ConReg links.
   */
  public function testFlattenedMenuOrder(): void {
    $openEid = $this->createEvent('Open event', TRUE);
    $closedEid = $this->createEvent('Closed event', FALSE);
    $this->menuLinkManager->rebuild();

    $this->assertSame([
      'conreg.events',
      "conreg.event_links:conreg_summary_$openEid",
      "conreg.event_links:conreg_admin_$openEid",
      "conreg.event_links:conreg_checkin_$openEid",
      "conreg.event_links:conreg_fantable_$openEid",
      "conreg.event_links:conreg_details_$openEid",
      "conreg.event_links:conreg_options_$openEid",
      "conreg.event_links:conreg_addons_$openEid",
      "conreg.event_links:conreg_children_$openEid",
      "conreg.event_links:conreg_bulk_email_$openEid",
      "conreg.event_links:conreg_email_list_$openEid",
      'conreg.label_printing_settings',
      "conreg_discord.event_links:conreg_discord_$openEid",
      "conreg.event_links:conreg_config_$openEid",
      "conreg.event_links:conreg_event_$closedEid",
    ], $this->sortedChildIds('conreg.overview'));
  }

  /**
   * Key links of a flattened event include the event name, unescaped.
   */
  public function testFlattenedTitlesIncludeEventName(): void {
    $openEid = $this->createEvent('Tom & Jerry Con', TRUE);
    $closedEid = $this->createEvent('Closed event', FALSE);
    $this->menuLinkManager->rebuild();

    $title = fn (string $id): string => $this->menuLinkManager->createInstance($id)->getTitle();

    $this->assertSame('Member summary (Tom & Jerry Con)', $title("conreg.event_links:conreg_summary_$openEid"));
    $this->assertSame('Administer members (Tom & Jerry Con)', $title("conreg.event_links:conreg_admin_$openEid"));
    $this->assertSame('Configure registration (Tom & Jerry Con)', $title("conreg.event_links:conreg_config_$openEid"));
    // Other moved links, and links in an event's own submenu, are unchanged.
    $this->assertSame('Check-in', $title("conreg.event_links:conreg_checkin_$openEid"));
    $this->assertSame('Member summary', $title("conreg.event_links:conreg_summary_$closedEid"));
  }

  /**
   * Without a flattened event, event submenus come after the other links.
   */
  public function testNestedMenuOrder(): void {
    $first = $this->createEvent('First event', TRUE);
    $second = $this->createEvent('Second event', TRUE);
    $this->menuLinkManager->rebuild();

    $this->assertSame([
      'conreg.events',
      'conreg.label_printing_settings',
      // Newest event first.
      "conreg.event_links:conreg_event_$second",
      "conreg.event_links:conreg_event_$first",
    ], $this->sortedChildIds('conreg.overview'));
  }

  /**
   * With two open events, both keep their own submenus.
   */
  public function testTwoOpenEventsStayNested(): void {
    $first = $this->createEvent('First event', TRUE);
    $second = $this->createEvent('Second event', TRUE);
    $this->menuLinkManager->rebuild();

    $this->assertNull($this->eventStorage->getTopLevelMenuEventId());
    $this->assertNested($first);
    $this->assertNested($second);
  }

  /**
   * With two closed events, both keep their own submenus.
   */
  public function testTwoClosedEventsStayNested(): void {
    $first = $this->createEvent('First event', FALSE);
    $second = $this->createEvent('Second event', FALSE);
    $this->menuLinkManager->rebuild();

    $this->assertNull($this->eventStorage->getTopLevelMenuEventId());
    $this->assertNested($first);
    $this->assertNested($second);
  }

  /**
   * Opening or closing an event through EventStorage updates the menu.
   */
  public function testTogglingOpenUpdatesMenu(): void {
    $first = $this->createEvent('First event', TRUE);
    $second = $this->createEvent('Second event', FALSE);
    $this->menuLinkManager->rebuild();
    $this->assertFlattened($first);

    // Opening the second event means neither stands out any more.
    $this->eventStorage->update(['eid' => $second, 'is_open' => 1]);
    $this->assertNested($first);
    $this->assertNested($second);

    // Closing the first leaves the second as the only open event.
    $this->eventStorage->update(['eid' => $first, 'is_open' => 0]);
    $this->assertNested($first);
    $this->assertFlattened($second);
  }

  /**
   * Adding a second event through EventStorage restores the submenu.
   */
  public function testAddingSecondEventRestoresNesting(): void {
    $first = $this->createEvent('First event', FALSE);
    $this->menuLinkManager->rebuild();
    $this->assertFlattened($first);

    $second = $this->eventStorage->insert([
      'event_name' => 'Second event',
      'is_open' => 0,
    ]);

    $this->assertNested($first);
    $this->assertNested($second);
  }

  /**
   * Inserts an event directly, bypassing EventStorage's menu rebuild.
   *
   * @param string $name
   *   The event name.
   * @param bool $open
   *   Whether the event is open for registration.
   *
   * @return int
   *   The new event ID.
   */
  protected function createEvent(string $name, bool $open): int {
    return (int) Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => $name, 'is_open' => (int) $open])
      ->execute();
  }

  /**
   * Asserts an event's links sit directly under ConReg with no event link.
   *
   * @param int $eid
   *   The event ID.
   */
  protected function assertFlattened(int $eid): void {
    $this->assertFalse(
      $this->menuLinkManager->hasDefinition("conreg.event_links:conreg_event_$eid"),
      "Event $eid's own parent link was removed.",
    );
    foreach ($this->childLinkIds($eid) as $id) {
      $link = $this->menuLinkManager->getDefinition($id);
      $this->assertSame('conreg.overview', $link['parent'], "$id moved under ConReg.");
    }
  }

  /**
   * Asserts an event's links sit under the event's own parent link.
   *
   * @param int $eid
   *   The event ID.
   */
  protected function assertNested(int $eid): void {
    $eventLinkId = "conreg.event_links:conreg_event_$eid";
    $this->assertTrue(
      $this->menuLinkManager->hasDefinition($eventLinkId),
      "Event $eid has its own parent link.",
    );
    $this->assertSame('conreg.overview', $this->menuLinkManager->getDefinition($eventLinkId)['parent']);
    foreach ($this->childLinkIds($eid) as $id) {
      $link = $this->menuLinkManager->getDefinition($id);
      $this->assertSame($eventLinkId, $link['parent'], "$id stayed under its event.");
    }
  }

  /**
   * Gets the IDs of a menu link's children, sorted by weight.
   *
   * @param string $parent
   *   The parent menu link plugin ID.
   *
   * @return string[]
   *   Menu link plugin IDs.
   */
  protected function sortedChildIds(string $parent): array {
    $children = array_filter(
      $this->menuLinkManager->getDefinitions(),
      fn (array $link): bool => $link['parent'] === $parent,
    );
    uasort($children, fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);
    return array_keys($children);
  }

  /**
   * Returns a core and a submodule per-event link ID for an event.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return string[]
   *   Menu link plugin IDs.
   */
  protected function childLinkIds(int $eid): array {
    return [
      "conreg.event_links:conreg_summary_$eid",
      "conreg.event_links:conreg_checkin_$eid",
      "conreg_discord.event_links:conreg_discord_$eid",
    ];
  }

}
