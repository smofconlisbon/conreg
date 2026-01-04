<?php

namespace Drupal\conreg_lookup\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\conreg\EventStorage;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver class to add extra links to the navigation menus.
 */
class LookupMenuDeriver extends DeriverBase implements ContainerDeriverInterface {

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
      $links["conreg_lookup_$eid"] = [
        'title' => $this->t("Lookup members"),
        'route_name' => 'conreg_member_lookup',
        'route_parameters' => ['eid' => $eid],
        'parent' => "conreg.event_links:conreg_event_$eid",
        'weight' => 21,
      ] + $base_plugin_definition;
    }

    return $links;
  }

}
