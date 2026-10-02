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
 * Tests that saving events keeps the admin menu's per-event links current.
 *
 * Per-event menu links come from derivers, which only run when the menu link
 * tree is rebuilt, so EventStorage must trigger that rebuild itself whenever
 * a change affects the menu.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class EventStorageMenuRebuildTest extends KernelTestBase {

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
    // Deleting an event deletes its rate plans.
    $this->installEntitySchema('conreg_rate_plan');

    $this->eventStorage = $this->container->get(EventStorage::class);
    $this->menuLinkManager = $this->container->get('plugin.manager.menu.link');

    // A second open event keeps every event in its own submenu, so each
    // event's parent link exists. With a lone event, MenuHooks would move
    // its links directly under ConReg; that is covered by
    // TopLevelEventMenuTest.
    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Background event', 'is_open' => 1])
      ->execute();

    // Start from a built menu tree, as a real site would have.
    $this->menuLinkManager->rebuild();
  }

  /**
   * Inserting an event adds its menu link.
   */
  public function testInsertAddsEventMenuLink(): void {
    $eid = $this->eventStorage->insert([
      'event_name' => 'New event',
      'is_open' => 1,
    ]);

    $this->assertTrue(
      $this->menuLinkManager->hasDefinition($this->eventLinkId($eid)),
      'A newly inserted event appears in the admin menu without a cache clear.',
    );
  }

  /**
   * Renaming an event updates its menu link title.
   */
  public function testRenameUpdatesEventMenuLinkTitle(): void {
    $eid = $this->insertEventAndRebuild('Old name');

    $this->eventStorage->update([
      'eid' => $eid,
      'event_name' => 'New name',
    ]);

    $link = $this->menuLinkManager->getDefinition($this->eventLinkId($eid));
    $this->assertSame('New name', (string) $link['title']);
  }

  /**
   * Deleting an event removes its menu link.
   */
  public function testDeleteRemovesEventMenuLink(): void {
    $eid = $this->insertEventAndRebuild('Doomed event');

    $this->eventStorage->delete(['eid' => $eid]);

    $this->assertFalse(
      $this->menuLinkManager->hasDefinition($this->eventLinkId($eid)),
      'A deleted event is removed from the admin menu without a cache clear.',
    );
  }

  /**
   * An update that changes no menu-relevant field doesn't rebuild the menu.
   *
   * EventConfig saves the whole event row on every settings save, so
   * rebuilding unconditionally would rebuild the menu tree on every save.
   * A rebuild is detected by adding a second event directly to the database,
   * bypassing EventStorage: its link only appears if a rebuild ran.
   */
  public function testUnchangedUpdateDoesNotRebuildMenu(): void {
    $eid = $this->insertEventAndRebuild('Unchanged event');

    $sneakyEid = (int) Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Sneaky event', 'is_open' => 1])
      ->execute();

    $this->eventStorage->update([
      'eid' => $eid,
      'event_name' => 'Unchanged event',
      'is_open' => 1,
    ]);

    $this->assertFalse(
      $this->menuLinkManager->hasDefinition($this->eventLinkId($sneakyEid)),
      'Saving an event without menu-relevant changes did not rebuild the menu.',
    );
  }

  /**
   * Inserts an event directly and rebuilds the menu to include it.
   *
   * Used to set up a known starting state that doesn't depend on
   * EventStorage::insert() triggering a rebuild.
   *
   * @param string $name
   *   The event name.
   *
   * @return int
   *   The new event ID.
   */
  protected function insertEventAndRebuild(string $name): int {
    $eid = (int) Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => $name, 'is_open' => 1])
      ->execute();
    $this->menuLinkManager->rebuild();
    $this->assertTrue($this->menuLinkManager->hasDefinition($this->eventLinkId($eid)));

    return $eid;
  }

  /**
   * Returns the plugin ID of an event's top-level admin menu link.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return string
   *   The menu link plugin ID.
   */
  protected function eventLinkId(int $eid): string {
    return "conreg.event_links:conreg_event_$eid";
  }

}
