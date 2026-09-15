<?php

declare(strict_types=1);

namespace Drupal\conreg;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of label sizes.
 */
final class LabelSizeListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('Machine name');
    $header['dimensions'] = $this->t('Dimensions');
    $header['rotate'] = $this->t('Rotation');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\conreg\Entity\LabelSize $entity */
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['dimensions'] = $this->t('@width x @height mm', [
      '@width' => $entity->getWidthMm(),
      '@height' => $entity->getHeightMm(),
    ]);
    $row['rotate'] = $this->t('@degrees°', ['@degrees' => $entity->getRotateDegrees()]);
    return $row + parent::buildRow($entity);
  }

}
