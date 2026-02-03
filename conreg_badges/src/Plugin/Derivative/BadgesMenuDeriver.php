<?php

namespace Drupal\conreg_badges\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\conreg\Service\EventStorage;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver class to add extra links to the navigation menus.
 */
final class BadgesMenuDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs the BadgesMenuDeriver.
   *
   * @param \Drupal\conreg\Service\EventStorage $eventStorage
   *   The event storage service.
   */
  public function __construct(protected EventStorage $eventStorage) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get(EventStorage::class)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $links = [];

    $events = $this->eventStorage->loadAll();
    foreach ($events as $event) {
      $eid = $event['eid'];

      $links["conreg_badges_$eid"] = [
        'title' => $this->t('Badge export'),
        'route_name' => 'conreg_badges_list',
        'route_parameters' => ['eid' => $eid],
        'parent' => "conreg.event_links:conreg_event_$eid",
        'weight' => 17,
      ] + $base_plugin_definition;

      $links["conreg_badge_print_$eid"] = [
        'title' => $this->t('Badge printing'),
        'route_name' => 'conreg_badges_print',
        'route_parameters' => ['eid' => $eid],
        'parent' => "conreg.event_links:conreg_event_$eid",
        'weight' => 18,
      ] + $base_plugin_definition;
    }

    return $links;
  }

}
