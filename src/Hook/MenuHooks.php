<?php

namespace Drupal\conreg\Hook;

use Drupal\conreg\Service\EventStorage;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Menu hook implementations for conreg.
 *
 * Kept separate from ConregHooks so this class - and the event storage it
 * depends on - only need to be loaded when the menu link tree is rebuilt.
 */
class MenuHooks {

  public function __construct(
    protected EventStorage $eventStorage,
  ) {}

  /**
   * Implements hook_menu_links_discovered_alter().
   *
   * When one event stands out (see
   * EventStorage::getTopLevelMenuEventId()), its per-event admin links are
   * moved directly under ConReg and its own parent link is removed. This
   * runs after every module's derivers, so links added by submodules under
   * the event's parent link move too, without the submodules knowing.
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(array &$links): void {
    $eid = $this->eventStorage->getTopLevelMenuEventId();
    if ($eid === NULL) {
      return;
    }

    $eventLinkId = "conreg.event_links:conreg_event_$eid";
    if (!isset($links[$eventLinkId])) {
      return;
    }

    $newParent = $links[$eventLinkId]['parent'];
    $eventName = $links[$eventLinkId]['title'];
    unset($links[$eventLinkId]);

    foreach ($links as $id => &$link) {
      if (($link['parent'] ?? '') === $eventLinkId) {
        $link['parent'] = $newParent;
        if (in_array($id, $this->namedLinkIds($eid), TRUE)) {
          // Menu link titles are plain text, escaped once when rendered, so
          // the name is appended directly rather than through a placeholder
          // that would escape it a second time.
          $link['title'] = "{$link['title']} ($eventName)";
        }
      }
    }
  }

  /**
   * Gets the moved links whose titles should include the event name.
   *
   * Without the event's own parent link, these would otherwise give no hint
   * which event they belong to.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return string[]
   *   Menu link plugin IDs.
   */
  protected function namedLinkIds(int $eid): array {
    return [
      "conreg.event_links:conreg_summary_$eid",
      "conreg.event_links:conreg_admin_$eid",
      "conreg.event_links:conreg_config_$eid",
    ];
  }

}
