<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Entity\LabelSize;
use Drupal\conreg\Service\LabelRenderer;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests LabelRenderer, the PHP port of the print agent's rendering.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class LabelRendererKernelTest extends KernelTestBase {

  /**
   * The system CJK fallback font path (mirrors LabelRenderer::CJK_FONT_PATH).
   */
  protected const CJK_FONT_PATH = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'options',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * The renderer under test.
   */
  protected LabelRenderer $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renderer = \Drupal::service(LabelRenderer::class);
  }

  /**
   * Helper: create and save a label size entity.
   */
  protected function createLabelSize(string $id, int $widthMm, int $heightMm): LabelSize {
    $labelSize = LabelSize::create([
      'id' => $id,
      'label' => $id,
      'width_mm' => $widthMm,
      'height_mm' => $heightMm,
      'rotate_degrees' => 90,
    ]);
    $labelSize->save();
    return $labelSize;
  }

  /**
   * Test the rendered PNG decodes and matches the swapped canvas size.
   */
  public function testRenderProducesDecodableImageWithExpectedDimensions(): void {
    $labelSize = $this->createLabelSize('test_size', 36, 89);

    $png = $this->renderer->render(
      ['badge_name' => 'Jane Doe'],
      $labelSize,
      ['badge_name' => 'middle'],
      2,
    );

    $info = getimagesizefromstring($png);
    $this->assertNotFalse($info);
    // The renderer composes a wide/short canvas (width/height swapped
    // relative to the label's physical size) - rotating to the final
    // tall/narrow media size is left to the print agent.
    $this->assertSame($labelSize->getHeightPx(), $info[0]);
    $this->assertSame($labelSize->getWidthPx(), $info[1]);
  }

  /**
   * Test a field positioned "none" is never drawn.
   *
   * Asserts byte-identical output to omitting the field's value
   * entirely, which is a stronger and simpler check than trying to
   * inspect pixels for absence of text.
   */
  public function testFieldWithNonePositionIsNotDrawn(): void {
    $labelSize = $this->createLabelSize('test_size_none', 28, 89);
    $positions = [
      'badge_name' => 'middle',
      'member_number' => 'bottom_left',
      'days_attending' => 'bottom_right',
      'badge_type' => 'none',
    ];

    $withBadgeType = $this->renderer->render(
      ['badge_name' => 'Jane Doe', 'member_number' => '123', 'days_attending' => 'Fri', 'badge_type' => 'Adult'],
      $labelSize, $positions, 2,
    );
    $withoutBadgeType = $this->renderer->render(
      ['badge_name' => 'Jane Doe', 'member_number' => '123', 'days_attending' => 'Fri', 'badge_type' => NULL],
      $labelSize, $positions, 2,
    );

    $this->assertSame($withBadgeType, $withoutBadgeType);
  }

  /**
   * Test that allowing more lines for the name never shrinks the font.
   *
   * Mirrors the equivalent test done for the original Python
   * implementation this class replaces. Asserts the algorithm's
   * actual invariant - trying strictly more line-split candidates
   * can only find an equal-or-better fit, and never exceeds the
   * requested line cap - rather than an exact line count/font size,
   * since those are sensitive to the active backend's own font
   * metrics (GD's and Imagick's measurements of the same text aren't
   * pixel-identical - see LabelRenderer::getActiveBackendName()).
   */
  public function testLongNameUsesMoreLinesAtLargerFontWhenAllowed(): void {
    $labelSize = $this->createLabelSize('test_size_lines', 28, 89);

    $ref = new \ReflectionClass($this->renderer);
    $method = $ref->getMethod('bestNameLayout');
    $method->setAccessible(TRUE);
    $createCanvas = $ref->getMethod('createCanvas');
    $createCanvas->setAccessible(TRUE);

    $canvasW = $labelSize->getHeightPx();
    $canvasH = $labelSize->getWidthPx();
    $maxWidth = $canvasW - 2 * (int) ($canvasW * 0.05);
    $maxHeight = $canvasH;
    $name = 'Alexandra Montgomery Fitzgerald';

    [$fontSize2, $lines2] = $method->invoke($this->renderer, $name, $maxWidth, $maxHeight, 2, $createCanvas->invoke($this->renderer, $canvasW, $canvasH));
    [$fontSize3, $lines3] = $method->invoke($this->renderer, $name, $maxWidth, $maxHeight, 3, $createCanvas->invoke($this->renderer, $canvasW, $canvasH));

    $this->assertLessThanOrEqual(2, count($lines2));
    $this->assertLessThanOrEqual(3, count($lines3));
    $this->assertGreaterThanOrEqual($fontSize2, $fontSize3);
  }

  /**
   * Regression test: a CJK-only name can still be split across lines.
   *
   * Found via manual testing: the line-break candidate search only
   * ever considered breaking at whitespace (`preg_split('/\s+/', ...)`),
   * but CJK text conventionally has no spaces between characters at
   * all - a long CJK name was stuck on a single line no matter how
   * high "number of lines for name" was set, since there was no word
   * boundary to break at. LabelRenderer::tokenizeName() now treats
   * each CJK character as its own breakable unit.
   */
  public function testCjkOnlyNameCanStillSplitAcrossLines(): void {
    $this->skipIfCjkFontUnavailable();
    $labelSize = $this->createLabelSize('test_size_cjk_lines', 28, 89);

    $ref = new \ReflectionClass($this->renderer);
    $method = $ref->getMethod('bestNameLayout');
    $method->setAccessible(TRUE);
    $createCanvas = $ref->getMethod('createCanvas');
    $createCanvas->setAccessible(TRUE);

    $canvasW = $labelSize->getHeightPx();
    $canvasH = $labelSize->getWidthPx();
    $maxWidth = $canvasW - 2 * (int) ($canvasW * 0.05);
    $maxHeight = $canvasH;
    $name = '王小明陳大文李美玲張偉';

    [$fontSize1, $lines1] = $method->invoke($this->renderer, $name, $maxWidth, $maxHeight, 1, $createCanvas->invoke($this->renderer, $canvasW, $canvasH));
    [$fontSize2, $lines2] = $method->invoke($this->renderer, $name, $maxWidth, $maxHeight, 2, $createCanvas->invoke($this->renderer, $canvasW, $canvasH));

    $this->assertCount(1, $lines1);
    $this->assertGreaterThan(1, count($lines2));
    $this->assertGreaterThan($fontSize1, $fontSize2);
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
