<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

/**
 * A drawing surface `LabelRenderer`'s layout algorithm renders onto.
 *
 * Abstracts exactly the handful of operations that differ between
 * image backends (GD vs Imagick) - text measurement, text drawing,
 * and PNG output - so the row/column layout math and the auto-fit
 * line-splitting search (both pure PHP, backend-agnostic) don't need
 * to know or care which one is actually drawing.
 */
interface LabelCanvasInterface {

  /**
   * Measures `$text` at `$fontSizePx`, normalized to a PIL-like box.
   *
   * The box is relative to a (0, 0) draw origin at the text baseline:
   * negative `top`/`left` mean the ink extends above/before the
   * origin, matching how `LabelRenderer`'s layout math already
   * expects to receive it.
   *
   * @return array
   *   Keys: left, top, right, bottom, width, height.
   */
  public function measureText(string $text, int $fontSizePx): array;

  /**
   * Draws `$text` with its measured box's top-left corner at ($x, $drawTop).
   *
   * @param string $text
   *   The text to draw.
   * @param int $fontSizePx
   *   Font size, in pixels.
   * @param float $x
   *   Bearing-corrected horizontal draw position (see
   *   `LabelRenderer::xForColumn()`).
   * @param float $drawTop
   *   The vertical position of the box's top edge.
   * @param array $box
   *   The box previously returned by measureText() for this exact
   *   `$text`/`$fontSizePx`, so implementations don't re-measure.
   */
  public function drawText(string $text, int $fontSizePx, float $x, float $drawTop, array $box): void;

  /**
   * Finishes the canvas and returns it as PNG bytes.
   */
  public function toPng(): string;

}
