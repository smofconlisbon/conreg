<?php

namespace Drupal\conreg\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\conreg\Service\EventStorage;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver class to add extra links to the navigation menus.
 *
 * Per-event links and the top-level links under ConReg share one weight
 * scale, so they sort the same way whether an event's links are in its own
 * submenu or moved directly under ConReg (see MenuHooks). Weights fall into
 * these groups, spaced by 10 so submodules can slot links in between:
 * - 10-99: day-to-day tasks (member summary, check-in, lookup).
 * - 100-199: reports (member details, selected options).
 * - 200-299: outreach and exports (bulk email, badge export and printing).
 * - 300-389: configuration (site-wide and per-event settings).
 * - 390: "Configure registration", always the last per-event link.
 * - 1000+: event submenus, always last.
 */
final class EventsMenuDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs a new EventsMenuDeriver.
   */
  public function __construct(
    protected EventStorage $eventStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get(EventStorage::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $links = [];

    $events = $this->eventStorage->loadAll();
    // Event submenus come after every other ConReg link, newest first.
    $weight = 1000 + count($events);

    foreach ($events as $event) {
      $eid = $event['eid'];
      $parent_id = "conreg_event_$eid";

      $links[$parent_id] = [
        'title' => $event['event_name'],
        'route_name' => 'conreg_event_overview',
        'route_parameters' => ['eid' => $eid],
        'parent' => 'conreg.overview',
        'weight' => $weight,
      ] + $base_plugin_definition;

      $links["conreg_summary_$eid"] = [
        'title' => $this->t('Member summary'),
        'route_name' => 'conreg_admin_member_summary',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 10,
      ] + $base_plugin_definition;

      $links["conreg_admin_$eid"] = [
        'title' => $this->t('Administer members'),
        'route_name' => 'conreg_admin_members',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 20,
      ] + $base_plugin_definition;

      $links["conreg_details_$eid"] = [
        'title' => $this->t('List all member details'),
        'route_name' => 'conreg_admin_member_list',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 100,
      ] + $base_plugin_definition;

      $links["conreg_email_list_$eid"] = [
        'title' => $this->t('Export email mailing list'),
        'route_name' => 'conreg_admin_mailout_emails',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 220,
      ] + $base_plugin_definition;

      $links["conreg_options_$eid"] = [
        'title' => $this->t('Selected options'),
        'route_name' => 'conreg_admin_member_options',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 110,
      ] + $base_plugin_definition;

      $links["conreg_addons_$eid"] = [
        'title' => $this->t('Add-ons'),
        'route_name' => 'conreg_admin_member_addons',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 120,
      ] + $base_plugin_definition;

      $links["conreg_children_$eid"] = [
        'title' => $this->t('Child members'),
        'route_name' => 'conreg_admin_child_member_ages',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 130,
      ] + $base_plugin_definition;

      $links["conreg_fantable_$eid"] = [
        'title' => $this->t('Fan table registration'),
        'route_name' => 'conreg_admin_fantable',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 50,
      ] + $base_plugin_definition;

      $links["conreg_checkin_$eid"] = [
        'title' => $this->t('Check-in'),
        'route_name' => 'conreg_admin_checkin',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 40,
      ] + $base_plugin_definition;

      $links["conreg_bulk_email_$eid"] = [
        'title' => $this->t('Bulk email sending'),
        'route_name' => 'conreg_admin_bulk_email',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 200,
      ] + $base_plugin_definition;

      $links["conreg_config_$eid"] = [
        'title' => $this->t('Configure registration'),
        'route_name' => 'conreg_config',
        'route_parameters' => ['eid' => $eid],
        'parent' => $base_plugin_definition['id'] . ':' . $parent_id,
        'weight' => 390,
      ] + $base_plugin_definition;

      $weight--;
    }

    return $links;
  }

}
