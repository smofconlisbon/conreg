<?php

namespace Drupal\conreg_planz\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\conreg\EventStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver class to add extra links to the navigation menus.
 */
final class PlanZMenuDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static();
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $links = [];

    $events = EventStorage::loadAll();
    foreach ($events as $event) {
      $eid = $event['eid'];
      $links["conreg_planz_admin_$eid"] = [
        'title' => $this->t("Send PlanZ invitations"),
        'route_name' => 'conreg_config_planz_admin',
        'route_parameters' => ['eid' => $eid],
        'parent' => "conreg.event_links:conreg_event_$eid",
        'weight' => 12,
      ] + $base_plugin_definition;

      $links["conreg_planz_config_$eid"] = [
        'title' => $this->t("Configure PlanZ integration"),
        'route_name' => 'conreg_config_planz_options',
        'route_parameters' => ['eid' => $eid],
        'parent' => "conreg.event_links:conreg_event_$eid",
        'weight' => 13,
      ] + $base_plugin_definition;
    }

    return $links;
  }

}
