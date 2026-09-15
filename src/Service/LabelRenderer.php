<?php

declare(strict_types=1);

// cspell:ignore itertools
namespace Drupal\conreg\Service;

use Drupal\conreg\Entity\LabelSize;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Renders badge labels as PNG images.
 *
 * A faithful port of the print agent's original print_label.py
 * rendering algorithm (see docs/site-building/label-printing.md),
 * moved into ConReg so a real print job and an on-demand preview go
 * through the exact same renderer with no print agent involved.
 *
 * Renders on a natural, wide "reading" canvas (width/height swapped
 * relative to the label's physical dimensions). Rotating that canvas
 * to match the media's physical feed orientation is a print-time
 * concern left entirely to the print agent - this service never
 * rotates, never applies "number of copies," and knows nothing about
 * printers.
 */
class LabelRenderer {

  use SplitsFontRunsTrait;

  public function __construct(
    protected ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Every label size uses the same printer hardware/DPI.
   */
  protected const DPI = 300;

  /**
   * Primary font, overridden per-run by a fallback below when needed.
   *
   * The same file the print agent used to render with, so a fleet of
   * print-server devices doesn't need its own font (or any rendering
   * code) any more. Covers Latin (incl. diacritics), Cyrillic, Greek,
   * and similar scripts; has no CJK or emoji glyphs at all.
   */
  protected const FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

  /**
   * Fallback font for CJK ideographs/kana/hangul (see SplitsFontRunsTrait).
   *
   * A system-level requirement (`fonts-noto-cjk`, ~90MB installed) -
   * see docs/getting-started/requirements.md.
   */
  protected const CJK_FONT_PATH = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc';

  /**
   * Fallback font for emoji/astral-plane codepoints.
   *
   * Bundled with the module (assets/fonts/, SIL Open Font License —
   * see assets/fonts/NotoEmoji-LICENSE.txt) rather than relying on a
   * system package, since no monochrome emoji font is available as
   * one in common distro repos; the only widely-packaged option
   * (Noto Color Emoji) is a color/bitmap font neither GD nor Imagick
   * can use for text rendering at all. GD still can't decode the
   * astral-plane codepoints this font would need in the first place
   * (see sanitizeText()), so in practice only ImagickLabelCanvas ever
   * actually draws with it.
   */
  protected const EMOJI_FONT_RELATIVE_PATH = 'assets/fonts/NotoEmoji-Regular.ttf';

  /**
   * Size for member_number/days_attending/badge_type, in points.
   *
   * Independent of the badge name's auto-sized font.
   */
  protected const DETAIL_FONT_PT = 14;

  /**
   * The 7 addressable slots, mapped to [row, column].
   *
   * A 3x3 grid missing middle-left/middle-right, plus "none" ("don't
   * print").
   */
  protected const SLOTS = [
    'top_left' => ['top', 'left'],
    'top_center' => ['top', 'center'],
    'top_right' => ['top', 'right'],
    'middle' => ['middle', 'center'],
    'bottom_left' => ['bottom', 'left'],
    'bottom_center' => ['bottom', 'center'],
    'bottom_right' => ['bottom', 'right'],
    'none' => NULL,
  ];

  /**
   * The four positionable fields, in a fixed processing order.
   */
  protected const FIELD_KEYS = ['badge_name', 'member_number', 'days_attending', 'badge_type'];

  /**
   * Renders a label to PNG bytes.
   *
   * @param array $fields
   *   An array with keys badge_name, member_number, days_attending,
   *   badge_type - any value may be NULL/empty/absent.
   * @param \Drupal\conreg\Entity\LabelSize $labelSize
   *   The label size to render for.
   * @param array $fieldPositions
   *   Field key => position slot (see self::SLOTS). Missing keys
   *   default to 'none'.
   * @param int $nameLines
   *   The largest number of lines the badge name may be split across.
   *
   * @return string
   *   Raw, unrotated PNG bytes.
   */
  public function render(array $fields, LabelSize $labelSize, array $fieldPositions, int $nameLines): string {
    if (!$this->imagickAvailable()) {
      // GD mis-decodes astral-plane (mainly emoji) codepoints outright,
      // regardless of font - Imagick decodes them correctly and draws
      // them via the bundled emoji font (see createCanvas()), so this
      // only applies to the GD fallback path.
      $fields = array_map([$this, 'sanitizeText'], $fields);
    }

    $widthPx = $labelSize->getWidthPx();
    $heightPx = $labelSize->getHeightPx();
    // Swapped: compose wide and short. Rotating to the final tall/
    // narrow media size is left to the print agent.
    $canvasW = $heightPx;
    $canvasH = $widthPx;

    $canvas = $this->createCanvas($canvasW, $canvasH);

    $sideMargin = (int) ($canvasW * 0.05);
    $vertMargin = (int) ($canvasH * 0.08);

    $detailFontPx = (int) round(self::DETAIL_FONT_PT * self::DPI / 72);
    // Imagick's box measurements are sub-pixel floats (unlike GD's
    // always-integer ones) - rounded here since row heights only ever
    // need to be pixel-accurate, keeping the row/column layout math
    // below backend-agnostic.
    $detailRowHeight = (int) round($canvas->measureText('Ag', $detailFontPx)['height']);

    // Which fields are *configured* to appear in each row (regardless
    // of whether this label actually has text for them) - matches the
    // original's "always reserve the row" behavior so layout stays
    // consistent label to label.
    $rowFields = ['top' => [], 'middle' => [], 'bottom' => []];
    $nameRow = NULL;
    foreach (self::FIELD_KEYS as $fieldKey) {
      $slot = self::SLOTS[$fieldPositions[$fieldKey] ?? 'none'] ?? NULL;
      if ($slot === NULL) {
        continue;
      }
      [$row, $col] = $slot;
      $rowFields[$row][] = [$col, $fieldKey];
      if ($fieldKey === 'badge_name') {
        $nameRow = $row;
      }
    }

    $topOccupied = !empty($rowFields['top']);
    $middleOccupied = !empty($rowFields['middle']);
    $bottomOccupied = !empty($rowFields['bottom']);

    $topMargin = $topOccupied ? $vertMargin : 0;
    $bottomMargin = $bottomOccupied ? $vertMargin : 0;

    $fixedH = 0;
    if ($topOccupied && $nameRow !== 'top') {
      $fixedH += $topMargin + $detailRowHeight;
    }
    if ($middleOccupied && $nameRow !== 'middle') {
      $fixedH += $detailRowHeight;
    }
    if ($bottomOccupied && $nameRow !== 'bottom') {
      $fixedH += $bottomMargin + $detailRowHeight;
    }

    $flexibleH = $nameRow !== NULL ? max(0, $canvasH - $fixedH) : 0;

    $rowAlloc = function (string $row) use ($nameRow, $flexibleH, $topOccupied, $topMargin, $bottomOccupied, $bottomMargin, $middleOccupied, $detailRowHeight): int {
      if ($row === $nameRow) {
        return $flexibleH;
      }
      if ($row === 'top' && $topOccupied) {
        return $topMargin + $detailRowHeight;
      }
      if ($row === 'bottom' && $bottomOccupied) {
        return $bottomMargin + $detailRowHeight;
      }
      if ($row === 'middle' && $middleOccupied) {
        return $detailRowHeight;
      }
      return 0;
    };

    // Top of each row's *content* region (i.e. after any leading
    // margin), and that region's height.
    $contentTop = [];
    $contentHeight = [];
    $y = 0;
    foreach (['top', 'middle', 'bottom'] as $row) {
      $alloc = $rowAlloc($row);
      $marginHere = ($row === 'top' && $topOccupied && $row !== $nameRow) ? $topMargin : 0;
      $contentTop[$row] = $y + $marginHere;
      $contentHeight[$row] = $row !== $nameRow ? $alloc - $marginHere : $alloc;
      $y += $alloc;
    }

    // --- Detail fields (everything except badge_name) ---
    foreach (['top', 'middle', 'bottom'] as $row) {
      if ($row === $nameRow) {
        continue;
      }
      foreach ($rowFields[$row] as [$col, $fieldKey]) {
        $text = $fields[$fieldKey] ?? NULL;
        if ($text === NULL || $text === '') {
          continue;
        }
        $box = $canvas->measureText($text, $detailFontPx);
        $x = $this->xForColumn($col, $canvasW, $sideMargin, $box['width'], $box['left']);
        if ($row === 'top') {
          $drawTop = $contentTop[$row];
        }
        elseif ($row === 'bottom') {
          $drawTop = $contentTop[$row] + $contentHeight[$row] - $box['height'];
        }
        else {
          $drawTop = $contentTop[$row] + ($contentHeight[$row] - $box['height']) / 2;
        }
        $canvas->drawText($text, $detailFontPx, $x, $drawTop, $box);
      }
    }

    // --- Badge name: auto-fit within whichever row it's assigned ---
    if ($nameRow !== NULL) {
      $nameText = $fields['badge_name'] ?? NULL;
      if ($nameText !== NULL && $nameText !== '') {
        $nameAreaHeight = $contentHeight[$nameRow];
        $maxTextWidth = $canvasW - 2 * $sideMargin;

        [$fontSize, $lines, $lineBoxes, $spacing] = $this->bestNameLayout($nameText, $maxTextWidth, $nameAreaHeight, $nameLines, $canvas);

        $heights = array_map(fn(array $b) => $b['height'], $lineBoxes);
        $totalBlockHeight = array_sum($heights) + $spacing * (count($lines) - 1);
        $y = $contentTop[$nameRow] + ($nameAreaHeight - $totalBlockHeight) / 2;

        foreach ($lines as $i => $line) {
          $box = $lineBoxes[$i];
          $w = $box['width'];
          $x = ($canvasW - $w) / 2 - $box['left'];
          $canvas->drawText($line, $fontSize, $x, $y, $box);
          $y += $heights[$i] + $spacing;
        }
      }
    }

    return $canvas->toPng();
  }

  /**
   * Picks a drawing backend: Imagick if loaded, GD otherwise.
   *
   * GD ships with PHP by default and is always available, but its
   * text layout mis-decodes 4-byte "astral plane" UTF-8 (mainly
   * emoji) - Imagick handles this correctly, so it's preferred when
   * present. See getActiveBackendName(), which reports this same
   * decision for the Label Printing Settings page.
   */
  protected function createCanvas(int $width, int $height): LabelCanvasInterface {
    $emojiFontPath = $this->emojiFontPath();
    if ($this->imagickAvailable()) {
      return new ImagickLabelCanvas($width, $height, self::FONT_PATH, self::CJK_FONT_PATH, $emojiFontPath);
    }
    return new GdLabelCanvas($width, $height, self::FONT_PATH, self::CJK_FONT_PATH, $emojiFontPath);
  }

  /**
   * Absolute path to the bundled emoji fallback font.
   */
  protected function emojiFontPath(): string {
    return DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('conreg') . '/' . self::EMOJI_FONT_RELATIVE_PATH;
  }

  /**
   * Whether the `imagick` PHP extension is loaded.
   *
   * A system-level dependency (see
   * docs/getting-started/requirements.md) that Composer can't vendor
   * in - optional, not required, since GD is the always-available
   * fallback.
   */
  protected function imagickAvailable(): bool {
    return extension_loaded('imagick');
  }

  /**
   * Reports which backend rendering is actually using right now.
   *
   * Shown on the Label Printing Settings page so an admin can tell
   * whether full Unicode support (Imagick) or the GD fallback
   * (accented Latin/Cyrillic/Greek/etc. work; emoji and other
   * 4-byte-UTF-8 characters may render incorrectly) is in effect.
   */
  public function getActiveBackendName(): string {
    return $this->imagickAvailable() ? 'imagick' : 'gd';
  }

  /**
   * Replaces astral-plane characters (mainly emoji) with "?".
   *
   * GD-only (see render()): GD mis-decodes these 4-byte UTF-8
   * sequences into several bogus glyphs no matter which font is
   * asked to draw them, so there's nothing useful to preserve on that
   * backend. Imagick decodes them correctly and draws them with the
   * bundled emoji font instead (see createCanvas()/
   * SplitsFontRunsTrait), so this is skipped there entirely.
   */
  protected function sanitizeText(?string $text): ?string {
    if ($text === NULL) {
      return NULL;
    }
    return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $text);
  }

  /**
   * Horizontal draw position for a text block aligned to `$col`.
   *
   * `$textW` is the block's width and `$boxLeft` its bbox left offset.
   */
  protected function xForColumn(string $col, int $canvasW, int $sideMargin, float $textW, float $boxLeft): float {
    if ($col === 'left') {
      return $sideMargin - $boxLeft;
    }
    if ($col === 'right') {
      return $canvasW - $sideMargin - $textW - $boxLeft;
    }
    return ($canvasW - $textW) / 2 - $boxLeft;
  }

  /**
   * Finds the largest font size at which `$lines` fits the given box.
   *
   * Every line must fit within `$maxWidth`, and the whole stacked
   * block (with spacing between lines) must fit within `$maxHeight`.
   *
   * @return array
   *   [font_size, line_boxes, spacing].
   */
  protected function fitLines(array $lines, int $maxWidth, int $maxHeight, LabelCanvasInterface $canvas, int $maxStartSize = 400): array {
    $measureAt = function (int $fontSize) use ($lines, $canvas): array {
      $boxes = array_map(fn(string $line) => $canvas->measureText($line, $fontSize), $lines);
      $spacing = (int) ($fontSize * 0.15);
      return [$boxes, $spacing];
    };
    $fitsAt = function (int $fontSize) use ($measureAt, $lines, $maxWidth, $maxHeight): bool {
      [$boxes, $spacing] = $measureAt($fontSize);
      $widths = array_map(fn(array $b) => $b['width'], $boxes);
      $heights = array_map(fn(array $b) => $b['height'], $boxes);
      $totalHeight = array_sum($heights) + $spacing * (count($lines) - 1);
      return max($widths) <= $maxWidth && $totalHeight <= $maxHeight;
    };

    // Binary search the largest size in [10, $maxStartSize] that fits -
    // width/height both grow monotonically with font size, so there's
    // a single fits/doesn't-fit threshold. Far fewer measureText()
    // calls than a linear scan, which matters since
    // ImagickLabelCanvas's measurements aren't cheap (see its own
    // docblock).
    $low = 10;
    $high = $maxStartSize;
    $bestSize = 10;
    if ($fitsAt($high)) {
      $bestSize = $high;
    }
    elseif ($fitsAt($low)) {
      while ($high - $low > 1) {
        $mid = intdiv($low + $high, 2);
        if ($fitsAt($mid)) {
          $low = $mid;
        }
        else {
          $high = $mid;
        }
      }
      $bestSize = $low;
    }
    // Else: nothing in range fits - fall through with the smallest
    // size we tried, even though it doesn't fully fit.
    [$boxes, $spacing] = $measureAt($bestSize);
    return [$bestSize, $boxes, $spacing];
  }

  /**
   * Splits `$name` into breakable units for linePartitions().
   *
   * Word-boundary-only breaking (splitting purely on whitespace, as a
   * Latin-only implementation would) never finds a break point in CJK
   * text, which conventionally has no spaces between words/characters
   * at all - a long CJK name would otherwise be stuck on a single
   * line regardless of the "number of lines for name" setting. So
   * each CJK character is its own independently-breakable unit here,
   * while a run of non-CJK, non-whitespace characters (a "word", as
   * before) stays indivisible. Each unit also records whether a space
   * originally followed it, so reassembling units back into a line
   * (see linePartitions()) reproduces the original spacing - or lack
   * of it - instead of always inserting one.
   *
   * @return array<array{0: string, 1: bool}>
   *   [text, hasSpaceAfter] tuples, in original order.
   */
  protected function tokenizeName(string $name): array {
    $chars = preg_split('//u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $tokens = [];
    $word = '';
    foreach ($chars as $char) {
      if (preg_match('/\s/u', $char) === 1) {
        if ($word !== '') {
          $tokens[] = [$word, TRUE];
          $word = '';
        }
        elseif ($tokens) {
          // Collapse consecutive whitespace onto the previous token,
          // matching \s+ treating a run of spaces as one boundary.
          $tokens[count($tokens) - 1][1] = TRUE;
        }
        continue;
      }
      if ($this->isCjkCodepoint(mb_ord($char) ?: 0)) {
        if ($word !== '') {
          $tokens[] = [$word, FALSE];
          $word = '';
        }
        $tokens[] = [$char, FALSE];
        continue;
      }
      $word .= $char;
    }
    if ($word !== '') {
      $tokens[] = [$word, FALSE];
    }

    return $tokens;
  }

  /**
   * Joins tokens (see tokenizeName()) back into one line of text.
   *
   * Only reinserts a space where one originally followed a token -
   * never after the last token in the line, so a forced line break
   * never leaves a trailing space.
   */
  protected function joinTokens(array $tokens): string {
    $text = '';
    $last = count($tokens) - 1;
    foreach ($tokens as $i => [$tokenText, $hasSpaceAfter]) {
      $text .= $tokenText;
      if ($hasSpaceAfter && $i < $last) {
        $text .= ' ';
      }
    }
    return $text;
  }

  /**
   * All ways to split `$tokens` into 1..$maxLines contiguous groups.
   *
   * Token order is always preserved - only where the breaks fall
   * changes.
   *
   * @param array<array{0: string, 1: bool}> $tokens
   *   As returned by tokenizeName().
   * @param int $maxLines
   *   The largest number of lines to consider splitting into.
   */
  protected function linePartitions(array $tokens, int $maxLines): array {
    $maxLines = max(1, min($maxLines, count($tokens)));
    $partitions = [];
    for ($n = 1; $n <= $maxLines; $n++) {
      if ($n === 1) {
        $partitions[] = [$this->joinTokens($tokens)];
        continue;
      }
      foreach ($this->combinations(range(1, count($tokens) - 1), $n - 1) as $cuts) {
        $bounds = array_merge([0], $cuts, [count($tokens)]);
        $line = [];
        for ($i = 0; $i < count($bounds) - 1; $i++) {
          $line[] = $this->joinTokens(array_slice($tokens, $bounds[$i], $bounds[$i + 1] - $bounds[$i]));
        }
        $partitions[] = $line;
      }
    }
    return $partitions;
  }

  /**
   * All k-combinations of `$items` (PHP has no itertools.combinations).
   */
  protected function combinations(array $items, int $k): array {
    $n = count($items);
    if ($k > $n || $k < 0) {
      return [];
    }
    if ($k === 0) {
      return [[]];
    }

    $result = [];
    $indices = range(0, $k - 1);
    while (TRUE) {
      $result[] = array_map(fn(int $i) => $items[$i], $indices);

      $i = $k - 1;
      while ($i >= 0 && $indices[$i] === $i + $n - $k) {
        $i--;
      }
      if ($i < 0) {
        break;
      }
      $indices[$i]++;
      for ($j = $i + 1; $j < $k; $j++) {
        $indices[$j] = $indices[$j - 1] + 1;
      }
    }
    return $result;
  }

  /**
   * Finds whichever line split lets `$name` print largest.
   *
   * Tries the name as a single line, and every way to split it across
   * up to `$maxLines` contiguous groups.
   *
   * Ties on font size are broken in favor of the narrowest split (the
   * smallest longest-line width).
   *
   * @return array
   *   [font_size, lines, line_boxes, spacing].
   */
  protected function bestNameLayout(string $name, int $maxWidth, int $maxHeight, int $maxLines, LabelCanvasInterface $canvas): array {
    $tokens = $this->tokenizeName($name);
    $candidates = $tokens ? $this->linePartitions($tokens, $maxLines) : [[$name]];

    $best = NULL;
    $bestWidth = NULL;
    foreach ($candidates as $lines) {
      [$fontSize, $boxes, $spacing] = $this->fitLines($lines, $maxWidth, $maxHeight, $canvas);
      $width = max(array_map(fn(array $b) => $b['width'], $boxes));
      if ($best === NULL || $fontSize > $best[0] || ($fontSize === $best[0] && $width < $bestWidth)) {
        $best = [$fontSize, $lines, $boxes, $spacing];
        $bestWidth = $width;
      }
    }

    return $best;
  }

}
