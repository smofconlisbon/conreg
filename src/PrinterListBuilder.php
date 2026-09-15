<?php

declare(strict_types=1);

namespace Drupal\conreg;

use Drupal\conreg\Service\EventStorage;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of printers.
 */
final class PrinterListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entityTypeManager,
    protected EventStorage $eventStorage,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $entityTypeManager->getStorage($entity_type->id()));
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager'),
      $container->get(EventStorage::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['name'] = $this->t('Name');
    $header['machine_name'] = $this->t('Machine name');
    $header['event'] = $this->t('Event');
    $header['last_seen'] = $this->t('Last seen');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\conreg\Entity\Printer $entity */
    $row['name'] = $entity->label();
    $row['machine_name'] = $entity->get('machine_name')->value;
    $row['event'] = $this->eventName((int) $entity->get('eid')->value);
    $row['last_seen'] = $this->lastSeen($entity);
    return $row + parent::buildRow($entity);
  }

  /**
   * Resolves an event ID to its name, falling back to the raw ID.
   */
  protected function eventName(int $eid): string {
    foreach ($this->eventStorage->eventOptions() as $event) {
      if ((int) $event['eid'] === $eid) {
        return $event['event_name'];
      }
    }
    return (string) $eid;
  }

  /**
   * Formats when a printer's agent last successfully polled, if ever.
   */
  protected function lastSeen(EntityInterface $entity): string {
    /** @var \Drupal\conreg\Entity\Printer $entity */
    $lastSeen = $entity->get('last_seen')->value;
    if (!$lastSeen) {
      return (string) $this->t('Never');
    }
    return (string) $this->t('@time ago', [
      '@time' => $this->dateFormatter->formatTimeDiffSince((int) $lastSeen),
    ]);
  }

}
