<?php

declare(strict_types=1);

namespace Drupal\conreg\Entity;

use Drupal\conreg\Form\Admin\LabelSizeForm;
use Drupal\conreg\LabelSizeListBuilder;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the label size entity.
 *
 * A named Dymo label size (width/height in mm, plus the rotation the
 * print agent needs to apply for that stock to feed correctly),
 * selectable on the global Label Printing Settings form. Global rather
 * than per-event, since it describes physical label stock, not
 * registration data.
 */
#[ConfigEntityType(
  id: 'conreg_label_size',
  label: new TranslatableMarkup('Label size'),
  label_collection: new TranslatableMarkup('Label sizes'),
  label_singular: new TranslatableMarkup('label size'),
  label_plural: new TranslatableMarkup('label sizes'),
  config_prefix: 'conreg_label_size',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => LabelSizeListBuilder::class,
    'form' => [
      'add' => LabelSizeForm::class,
      'edit' => LabelSizeForm::class,
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/conreg/label-printing/sizes',
    'add-form' => '/admin/config/conreg/label-printing/sizes/add',
    'edit-form' => '/admin/config/conreg/label-printing/sizes/{conreg_label_size}/edit',
    'delete-form' => '/admin/config/conreg/label-printing/sizes/{conreg_label_size}/delete',
  ],
  admin_permission: 'configure convention registration',
  label_count: [
    'singular' => '@count label size',
    'plural' => '@count label sizes',
  ],
  config_export: [
    'id',
    'label',
    'width_mm',
    'height_mm',
    'rotate_degrees',
  ],
)]
final class LabelSize extends ConfigEntityBase {

  /**
   * Rendering DPI.
   *
   * Every label size uses the same printer hardware/DPI; only the
   * physical dimensions differ.
   */
  protected const DPI = 300;

  /**
   * The label size machine name.
   */
  protected string $id;

  /**
   * The label size's human-readable name.
   */
  protected string $label;

  /**
   * The label stock width, in millimeters.
   */
  protected int $width_mm = 0;

  /**
   * The label stock height, in millimeters.
   */
  protected int $height_mm = 0;

  /**
   * Degrees to rotate the rendered canvas before printing.
   *
   * The print agent composes labels on a wide "reading" canvas and
   * rotates it to match the tall/narrow media orientation CUPS expects.
   * Some printers feed the opposite way, needing -90 instead of 90.
   */
  protected int $rotate_degrees = 90;

  /**
   * Gets the label stock width, in millimeters.
   */
  public function getWidthMm(): int {
    return $this->width_mm;
  }

  /**
   * Gets the label stock height, in millimeters.
   */
  public function getHeightMm(): int {
    return $this->height_mm;
  }

  /**
   * Gets the rotation, in degrees, to apply before printing.
   */
  public function getRotateDegrees(): int {
    return $this->rotate_degrees;
  }

  /**
   * Computes the CUPS "PageSize" keyword for this label size.
   *
   * CUPS identifies custom page sizes by their width/height in points
   * (1/72 inch), formatted as "w{width}h{height}" - e.g. "w79h252" for
   * the 28x89mm stock already in use. Computed here, rather than
   * stored, so there is a single source of truth for the conversion.
   */
  public function getCupsPageSize(): string {
    $widthPt = (int) round($this->width_mm / 25.4 * 72);
    $heightPt = (int) round($this->height_mm / 25.4 * 72);
    return "w{$widthPt}h{$heightPt}";
  }

  /**
   * Gets the label stock width, in pixels at render DPI.
   *
   * Uses int() truncation (not round()), matching the print agent's
   * original WIDTH_PX/HEIGHT_PX convention for the default 28x89mm size.
   */
  public function getWidthPx(): int {
    return (int) ($this->width_mm / 25.4 * self::DPI);
  }

  /**
   * Gets the label stock height, in pixels at render DPI.
   */
  public function getHeightPx(): int {
    return (int) ($this->height_mm / 25.4 * self::DPI);
  }

}
