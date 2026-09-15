<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit\Service;

// cspell:ignore garcía josé misdecodes notdef Ελένη Владимир
use Drupal\conreg\Service\GdLabelCanvas;
use Drupal\conreg\Service\ImagickLabelCanvas;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests GdLabelCanvas/ImagickLabelCanvas's text measurement across scripts.
 *
 * Plain unit tests (no Drupal bootstrap needed) since both classes are
 * self-contained given font paths. Covers a range of Unicode scripts a
 * real badge name might use, including ones DejaVu Sans Bold (the
 * primary font) has no glyphs for at all - CJK routes to a bundled
 * fallback font (see SplitsFontRunsTrait) and actually renders now,
 * and a regression check for the specific bug that motivated adding
 * Imagick as the preferred backend: GD mis-decodes 4-byte "astral
 * plane" UTF-8 (mainly emoji) into several bogus glyphs no matter
 * which font is asked to draw them, while Imagick decodes it as a
 * single codepoint and draws a real glyph from the bundled emoji font.
 */
#[Group('conreg')]
class LabelCanvasTest extends UnitTestCase {

  protected const FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

  protected const CJK_FONT_PATH = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc';

  protected const EMOJI_FONT_PATH = __DIR__ . '/../../../../assets/fonts/NotoEmoji-Regular.ttf';

  /**
   * Text samples covering scripts DejaVu Sans Bold has glyphs for.
   *
   * Each should measure as ordinary, sane text on any backend, using
   * only the primary font (no fallback routing involved).
   */
  public static function unicodeScriptProvider(): array {
    return [
      'accented Latin' => ['José García'],
      'Cyrillic' => ['Владимир'],
      'Greek' => ['Ελένη'],
    ];
  }

  /**
   * Test that GD measures a range of Unicode scripts without error.
   */
  #[DataProvider('unicodeScriptProvider')]
  public function testGdMeasuresUnicodeScripts(string $text): void {
    $canvas = $this->gdCanvas();
    $box = $canvas->measureText($text, 50);
    $this->assertGreaterThan(0, $box['width']);
    $this->assertGreaterThan(0, $box['height']);
  }

  /**
   * Test that Imagick measures a range of Unicode scripts without error.
   */
  #[DataProvider('unicodeScriptProvider')]
  public function testImagickMeasuresUnicodeScripts(string $text): void {
    $this->skipIfImagickUnavailable();
    $canvas = $this->imagickCanvas();
    $box = $canvas->measureText($text, 50);
    $this->assertGreaterThan(0, $box['width']);
    $this->assertGreaterThan(0, $box['height']);
  }

  /**
   * Test that GD actually renders CJK via the bundled fallback font.
   *
   * DejaVu Sans Bold has no CJK glyphs at all - this asserts the
   * fallback-font routing (SplitsFontRunsTrait) produces real,
   * sensibly-scaling ink, not the font's own empty ".notdef" boxes.
   */
  public function testGdRendersCjkViaFallbackFont(): void {
    $this->skipIfCjkFontUnavailable();
    $canvas = $this->gdCanvas();
    $single = $canvas->measureText('王', 50);
    $triple = $canvas->measureText('王小明', 50);
    $this->assertGreaterThan(10, $single['width']);
    $this->assertGreaterThan(10, $single['height']);
    // Three ideographs should measure roughly 3x one, not ~1x (which
    // would indicate only the first character was actually drawn).
    $this->assertGreaterThan($single['width'] * 2, $triple['width']);
  }

  /**
   * Test that Imagick actually renders CJK via the bundled fallback font.
   */
  public function testImagickRendersCjkViaFallbackFont(): void {
    $this->skipIfImagickUnavailable();
    $this->skipIfCjkFontUnavailable();
    $canvas = $this->imagickCanvas();
    $single = $canvas->measureText('王', 50);
    $triple = $canvas->measureText('王小明', 50);
    $this->assertGreaterThan(10, $single['width']);
    $this->assertGreaterThan(10, $single['height']);
    $this->assertGreaterThan($single['width'] * 2, $triple['width']);
  }

  /**
   * Regression test: a space right at a script boundary isn't dropped.
   *
   * Found via manual testing: ImagickLabelCanvas's trim()-based ink
   * measurement (see ImagickLabelCanvas::measureCoreInkAtReferenceSize())
   * has no ink to find in whitespace, so a run ending or starting with
   * a space exactly where a font switches (e.g. "Wang " before a CJK
   * run) silently lost that space's width entirely - "Wang 王" would
   * measure identically to "Wang王" and the two scripts would be drawn
   * touching, with no visible gap. Comparing against a self-referential
   * width sum (e.g. measureText('Wang ')+measureText('王')) does NOT
   * catch this, since both sides of that comparison share the same
   * bug and stay silently consistent with each other - this instead
   * compares against text with vs. without the space to detect it.
   */
  public function testSpaceAtScriptBoundaryIsNotDropped(): void {
    $this->skipIfCjkFontUnavailable();
    foreach ($this->availableBackends() as $canvas) {
      $withSpace = $canvas->measureText('Wang 王', 50)['width'];
      $withoutSpace = $canvas->measureText('Wang王', 50)['width'];
      $this->assertGreaterThan(
        $withoutSpace + 5,
        $withSpace,
        get_class($canvas) . ': a space at a script boundary should measurably widen the text.',
      );
    }
  }

  /**
   * Test a mixed-script name measures as the sum of its runs.
   *
   * E.g. a Western given name plus a CJK family name - not a crash,
   * and not a degenerate/overlapping result.
   */
  public function testMixedScriptRunsMeasureAsCombinedWidth(): void {
    $this->skipIfCjkFontUnavailable();
    foreach ($this->availableBackends() as $canvas) {
      $latinOnly = $canvas->measureText('Wang ', 50)['width'];
      $cjkOnly = $canvas->measureText('王', 50)['width'];
      $mixed = $canvas->measureText('Wang 王', 50)['width'];
      $this->assertEqualsWithDelta(
        $latinOnly + $cjkOnly,
        $mixed,
        max(2.0, ($latinOnly + $cjkOnly) * 0.05),
        get_class($canvas) . ': mixed-script width should be close to the sum of its runs.',
      );
    }
  }

  /**
   * Regression test: GD mis-decodes a 4-byte UTF-8 (emoji) sequence.
   *
   * Confirmed empirically: at font size 50, DejaVu measures a single
   * "A" at ~51px wide; GD measures 😊 (one codepoint) at ~163px -
   * roughly 3x wider, consistent with the 4-byte sequence being split
   * into several bogus glyphs (the "ð" + three tofu boxes an admin
   * actually saw), regardless of which font would otherwise draw it.
   * This is exactly why Imagick is preferred when available - see
   * testImagickRendersEmojiGlyph().
   */
  public function testGdMisdecodesAstralPlaneCharacter(): void {
    $canvas = $this->gdCanvas();
    $singleCharWidth = $canvas->measureText('A', 50)['width'];
    $emojiWidth = $canvas->measureText('😊', 50)['width'];
    $this->assertGreaterThan($singleCharWidth * 2, $emojiWidth);
  }

  /**
   * Test that Imagick renders a real emoji glyph via the bundled font.
   *
   * Confirmed empirically the bundled monochrome Noto Emoji font
   * (assets/fonts/, see LabelRenderer::EMOJI_FONT_RELATIVE_PATH) draws
   * an actual smiley outline via Imagick, at a sane, single-glyph-scale
   * size - not the primary font's tiny/absent fallback box, and not
   * GD's garbled multi-glyph mess.
   */
  public function testImagickRendersEmojiGlyph(): void {
    $this->skipIfImagickUnavailable();
    $canvas = $this->imagickCanvas();
    $singleCharWidth = $canvas->measureText('A', 50)['width'];
    $emojiBox = $canvas->measureText('😊', 50);
    $this->assertGreaterThan(10, $emojiBox['width']);
    $this->assertLessThan($singleCharWidth * 3, $emojiBox['width']);
  }

  /**
   * Test that both backends produce a well-formed, decodable PNG.
   */
  public function testBothBackendsProduceDecodablePng(): void {
    foreach ($this->availableBackends() as $canvas) {
      $box = $canvas->measureText('Test', 40);
      $canvas->drawText('Test', 40, 10, 10, $box);
      $png = $canvas->toPng();
      $this->assertNotFalse(getimagesizefromstring($png), get_class($canvas) . ' produced an undecodable PNG.');
    }
  }

  /**
   * A GdLabelCanvas wired with all three fonts under test.
   */
  protected function gdCanvas(): GdLabelCanvas {
    return new GdLabelCanvas(2000, 2000, self::FONT_PATH, self::CJK_FONT_PATH, self::EMOJI_FONT_PATH);
  }

  /**
   * An ImagickLabelCanvas wired with all three fonts under test.
   */
  protected function imagickCanvas(): ImagickLabelCanvas {
    return new ImagickLabelCanvas(2000, 2000, self::FONT_PATH, self::CJK_FONT_PATH, self::EMOJI_FONT_PATH);
  }

  /**
   * Every backend available in the current environment.
   *
   * @return \Drupal\conreg\Service\LabelCanvasInterface[]
   *   GD is always included; Imagick only if the extension is loaded.
   */
  protected function availableBackends(): array {
    $backends = [$this->gdCanvas()];
    if (extension_loaded('imagick')) {
      $backends[] = $this->imagickCanvas();
    }
    return $backends;
  }

  /**
   * Skips the calling test if the imagick PHP extension isn't loaded.
   */
  protected function skipIfImagickUnavailable(): void {
    if (!extension_loaded('imagick')) {
      $this->markTestSkipped('The imagick PHP extension is not available in this environment.');
    }
  }

  /**
   * Skips the calling test if the system CJK fallback font isn't installed.
   *
   * The `fonts-noto-cjk` package (~90MB) is an optional system dependency
   * (see docs/getting-started/requirements.md) - only needed where CJK
   * badge names are actually expected - so environments without it
   * (e.g. a stock CI runner) can't exercise CJK-specific behavior at all.
   */
  protected function skipIfCjkFontUnavailable(): void {
    if (!is_readable(self::CJK_FONT_PATH)) {
      $this->markTestSkipped('The CJK fallback font (' . self::CJK_FONT_PATH . ') is not available in this environment.');
    }
  }

}
