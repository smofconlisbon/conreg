<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

/**
 * Renders a label using PHP's GD extension.
 *
 * The universally-available fallback backend - GD ships with PHP by
 * default on virtually every install, unlike Imagick. Its UTF-8
 * handling is solid for normal multi-byte text (accented Latin,
 * Cyrillic, Greek, etc.) but mis-decodes 4-byte "astral plane"
 * sequences (mainly emoji) into several garbled glyphs rather than a
 * clean single result, regardless of font - see
 * LabelRenderer::sanitizeText(), which strips those before this class
 * ever sees them. CJK/kana/hangul route to a fallback font (see
 * SplitsFontRunsTrait) since DejaVu Sans Bold has no glyphs for them.
 */
class GdLabelCanvas implements LabelCanvasInterface {

  use SplitsFontRunsTrait;

  /**
   * The underlying GD image resource.
   *
   * @var \GdImage
   */
  protected $image;

  /**
   * The allocated black color index used for all drawn text.
   */
  protected int $black;

  public function __construct(
    int $width,
    int $height,
    protected string $fontPath,
    protected string $cjkFontPath,
    protected string $emojiFontPath,
  ) {
    $this->image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($this->image, 255, 255, 255);
    $this->black = imagecolorallocate($this->image, 0, 0, 0);
    imagefilledrectangle($this->image, 0, 0, $width - 1, $height - 1, $white);
  }

  /**
   * {@inheritdoc}
   *
   * GD's imagettfbbox() returns 4 points relative to the text
   * baseline (negative y = above baseline, positive y = below),
   * rather than a top-left-anchored box. Each font run is measured
   * independently and laid out left-to-right, treating each run's own
   * ink width as its horizontal advance (ignores inter-run kerning,
   * which doesn't meaningfully apply between different scripts/fonts
   * anyway).
   */
  public function measureText(string $text, int $fontSizePx): array {
    $runs = $this->splitIntoFontRuns($text, $this->fontPath, $this->cjkFontPath, $this->emojiFontPath);

    $penX = 0.0;
    $left = NULL;
    $right = NULL;
    $top = NULL;
    $bottom = NULL;
    foreach ($runs as [$font, $runText]) {
      $runBox = imagettfbbox($fontSizePx, 0, $font, $runText);
      $runLeft = min($runBox[0], $runBox[6]);
      $runRight = max($runBox[2], $runBox[4]);
      $runTop = min($runBox[5], $runBox[7]);
      $runBottom = max($runBox[1], $runBox[3]);

      $left = $left === NULL ? $penX + $runLeft : min($left, $penX + $runLeft);
      $right = $right === NULL ? $penX + $runRight : max($right, $penX + $runRight);
      $top = $top === NULL ? $runTop : min($top, $runTop);
      $bottom = $bottom === NULL ? $runBottom : max($bottom, $runBottom);
      $penX += ($runRight - $runLeft);
    }

    return [
      'left' => $left,
      'top' => $top,
      'right' => $right,
      'bottom' => $bottom,
      'width' => $right - $left,
      'height' => $bottom - $top,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * `$x` is expected to already be bearing-corrected (see
   * LabelRenderer::xForColumn()), matching PIL's draw.text()
   * semantics even though GD's imagettftext() takes a baseline
   * position, not a top-left one. For a single-run string this is
   * identical to one direct imagettftext() call; multi-run strings
   * draw each run in its own font, advancing the pen by each run's
   * own ink width in turn.
   */
  public function drawText(string $text, int $fontSizePx, float $x, float $drawTop, array $box): void {
    $baselineY = $drawTop - $box['top'];
    $runs = $this->splitIntoFontRuns($text, $this->fontPath, $this->cjkFontPath, $this->emojiFontPath);

    $penX = $x;
    foreach ($runs as [$font, $runText]) {
      imagettftext($this->image, $fontSizePx, 0, (int) round($penX), (int) round($baselineY), $this->black, $font, $runText);
      $runBox = imagettfbbox($fontSizePx, 0, $font, $runText);
      $runLeft = min($runBox[0], $runBox[6]);
      $runRight = max($runBox[2], $runBox[4]);
      $penX += ($runRight - $runLeft);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function toPng(): string {
    ob_start();
    imagepng($this->image);
    $png = ob_get_clean();
    imagedestroy($this->image);
    return $png;
  }

}
