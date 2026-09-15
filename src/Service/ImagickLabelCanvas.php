<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

/**
 * Renders a label using the PHP Imagick extension, when available.
 *
 * Preferred over GdLabelCanvas when the `imagick` PHP extension is
 * loaded (a system-level dependency, not something Composer can
 * vendor in - see docs/getting-started/requirements.md), since its
 * text layout correctly decodes 4-byte "astral plane" UTF-8 sequences
 * (mainly emoji) that GD garbles regardless of font. CJK/kana/hangul
 * and emoji each route to their own fallback font (see
 * SplitsFontRunsTrait) since DejaVu Sans Bold has no glyphs for them.
 */
class ImagickLabelCanvas implements LabelCanvasInterface {

  use SplitsFontRunsTrait;

  /**
   * The underlying Imagick canvas.
   */
  protected \Imagick $imagick;

  /**
   * The drawing context queued text annotations are added to.
   */
  protected \ImagickDraw $draw;

  /**
   * Reference font size measureText() actually measures at, in px.
   *
   * TrueType glyph outlines scale linearly with font size, so
   * measuring once at a cheap fixed size and scaling arithmetically
   * for whatever size was actually asked for avoids the cost of
   * drawing+trimming a full-size scratch canvas on every call - which
   * matters since a font-size search calls this many times for the
   * same text at different candidate sizes.
   */
  protected const REFERENCE_FONT_SIZE = 100;

  /**
   * Per-"font|text" cache of the box measured at self::REFERENCE_FONT_SIZE.
   */
  protected array $referenceBoxCache = [];

  public function __construct(
    int $width,
    int $height,
    protected string $fontPath,
    protected string $cjkFontPath,
    protected string $emojiFontPath,
  ) {
    $this->imagick = new \Imagick();
    $this->imagick->newImage($width, $height, new \ImagickPixel('white'));
    $this->imagick->setImageFormat('png');

    $this->draw = new \ImagickDraw();
    $this->draw->setFont($fontPath);
    $this->draw->setFillColor(new \ImagickPixel('black'));
    $this->draw->setTextAntialias(TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * Each font run is measured independently (at a cheap reference
   * size, scaled - see measureRunAtReferenceSize()) and laid out
   * left-to-right, treating each run's own ink width as its
   * horizontal advance (ignores inter-run kerning, which doesn't
   * meaningfully apply between different scripts/fonts anyway).
   */
  public function measureText(string $text, int $fontSizePx): array {
    $runs = $this->splitIntoFontRuns($text, $this->fontPath, $this->cjkFontPath, $this->emojiFontPath);
    $scale = $fontSizePx / self::REFERENCE_FONT_SIZE;

    $penX = 0.0;
    $left = NULL;
    $right = NULL;
    $top = NULL;
    $bottom = NULL;
    foreach ($runs as [$font, $runText]) {
      $ref = $this->referenceBoxCache[$font . '|' . $runText] ??= $this->measureRunAtReferenceSize($runText, $font);
      $runLeft = $ref['left'] * $scale;
      $runRight = $ref['right'] * $scale;
      $runTop = $ref['top'] * $scale;
      $runBottom = $ref['bottom'] * $scale;

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
   * Measures one run's real extent, in one font, at the reference size.
   *
   * Leading/trailing whitespace is measured separately from the
   * non-whitespace core (see measureWhitespaceAdvance()) and added
   * back in as a pure offset/advance - the ink-extent measurement this
   * delegates to (measureCoreInkAtReferenceSize()) has no ink to find
   * in whitespace at all, so a run ending or beginning with a space
   * right where a script boundary falls (e.g. "Wang " before a CJK
   * run) would otherwise silently lose that space's width entirely,
   * visually colliding with the adjacent run.
   */
  protected function measureRunAtReferenceSize(string $text, string $font): array {
    if (preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $matches) === 1) {
      [, $leadingSpace, $core, $trailingSpace] = $matches;
    }
    else {
      $leadingSpace = '';
      $core = $text;
      $trailingSpace = '';
    }

    $leadingWidth = $leadingSpace === '' ? 0.0 : $this->measureWhitespaceAdvance($leadingSpace, $font);
    $trailingWidth = $trailingSpace === '' ? 0.0 : $this->measureWhitespaceAdvance($trailingSpace, $font);

    if ($core === '') {
      // Whitespace-only run - shouldn't normally arise given how
      // SplitsFontRunsTrait builds runs, but handle it rather than
      // drawing/trimming an entirely blank probe canvas.
      $advance = $leadingWidth + $trailingWidth;
      return ['left' => 0.0, 'top' => 0.0, 'right' => $advance, 'bottom' => 0.0, 'width' => $advance, 'height' => 0.0];
    }

    $coreBox = $this->measureCoreInkAtReferenceSize($core, $font);
    return [
      'left' => $coreBox['left'] + $leadingWidth,
      'top' => $coreBox['top'],
      'right' => $coreBox['right'] + $leadingWidth + $trailingWidth,
      'bottom' => $coreBox['bottom'],
      'width' => $coreBox['width'] + $trailingWidth,
      'height' => $coreBox['height'],
    ];
  }

  /**
   * Measures a non-whitespace run's real ink extent at the reference size.
   *
   * Draws onto a scratch canvas, generously sized so ink can't clip
   * regardless of the glyphs' actual bearing, and measures via
   * `trimImage()` - `queryFontMetrics()`'s reported bounds don't
   * reliably match where ink actually lands once drawn (verified
   * empirically: the mismatch grows with font size, large enough at
   * sizes a short badge name auto-fits to that text was observed
   * rendering off the top of the canvas entirely).
   */
  protected function measureCoreInkAtReferenceSize(string $text, string $font): array {
    $fontSizePx = self::REFERENCE_FONT_SIZE;
    $originX = (int) max(200, $fontSizePx * (mb_strlen($text) + 1));
    $originY = (int) max(200, $fontSizePx * 2);

    $probe = new \Imagick();
    $probe->newImage($originX * 2, $originY * 2, new \ImagickPixel('white'));
    $draw = new \ImagickDraw();
    $draw->setFont($font);
    $draw->setFillColor(new \ImagickPixel('black'));
    $draw->setFontSize($fontSizePx);
    $draw->annotation($originX, $originY, $text);
    $probe->drawImage($draw);
    $probe->trimImage(0);
    // getImagePage()'s width/height describe the page/canvas geometry
    // and are untouched by trimImage() - only its x/y offset reflects
    // the trim. The actual trimmed dimensions come from the image
    // itself.
    $page = $probe->getImagePage();
    $left = $page['x'] - $originX;
    $top = $page['y'] - $originY;
    $width = (float) $probe->getImageWidth();
    $height = (float) $probe->getImageHeight();
    $probe->clear();

    return [
      'left' => $left,
      'top' => $top,
      'right' => $left + $width,
      'bottom' => $top + $height,
      'width' => $width,
      'height' => $height,
    ];
  }

  /**
   * Measures whitespace's own advance width, via GD, at the reference size.
   *
   * Imagick's ink-based measurements (both `queryFontMetrics()` and
   * the `trimImage()` approach above) are blind to whitespace - there's
   * no ink to find. GD's `imagettfbbox()` was verified empirically to
   * correctly include leading/trailing whitespace advance in its
   * reported width, so it's used here purely as a measurement oracle
   * for this one value - GD ships with PHP by default (see
   * docs/getting-started/requirements.md), so it's always available
   * regardless of which backend is actually rendering. Measured via
   * the width difference between a reference character with and
   * without `$whitespace` appended, isolating just its contribution.
   */
  protected function measureWhitespaceAdvance(string $whitespace, string $font): float {
    $size = self::REFERENCE_FONT_SIZE;
    $withGap = imagettfbbox($size, 0, $font, 'A' . $whitespace . 'A');
    $withoutGap = imagettfbbox($size, 0, $font, 'AA');
    $widthWith = max($withGap[2], $withGap[4]) - min($withGap[0], $withGap[6]);
    $widthWithout = max($withoutGap[2], $withoutGap[4]) - min($withoutGap[0], $withoutGap[6]);
    return max(0.0, $widthWith - $widthWithout);
  }

  /**
   * {@inheritdoc}
   *
   * Queues one annotation per font run rather than drawing
   * immediately - all queued text is composited onto the image in one
   * pass in toPng(), matching how ImageMagick's drawing primitives are
   * meant to batch. Each run's font is set on the shared draw context
   * immediately before it's queued, since ImagickDraw captures current
   * state per-primitive at queue time.
   */
  public function drawText(string $text, int $fontSizePx, float $x, float $drawTop, array $box): void {
    $baselineY = $drawTop - $box['top'];
    $runs = $this->splitIntoFontRuns($text, $this->fontPath, $this->cjkFontPath, $this->emojiFontPath);
    $scale = $fontSizePx / self::REFERENCE_FONT_SIZE;

    $penX = $x;
    foreach ($runs as [$font, $runText]) {
      $this->draw->setFont($font);
      $this->draw->setFontSize($fontSizePx);
      $this->draw->annotation($penX, $baselineY, $runText);

      $ref = $this->referenceBoxCache[$font . '|' . $runText] ??= $this->measureRunAtReferenceSize($runText, $font);
      $penX += ($ref['right'] - $ref['left']) * $scale;
    }
    // Leave the shared draw context pointed at the primary font, so a
    // subsequent measureText() probe (which always uses its own
    // throwaway ImagickDraw) is unaffected either way - restored here
    // purely so this object's own $this->draw stays in its documented
    // "primary font" default state between calls.
    $this->draw->setFont($this->fontPath);
  }

  /**
   * {@inheritdoc}
   */
  public function toPng(): string {
    $this->imagick->drawImage($this->draw);
    $blob = $this->imagick->getImageBlob();
    $this->imagick->clear();
    return $blob;
  }

}
