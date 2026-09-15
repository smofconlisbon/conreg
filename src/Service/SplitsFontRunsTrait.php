<?php

declare(strict_types=1);

// cspell:ignore halfwidth fullwidth
namespace Drupal\conreg\Service;

/**
 * Splits text into runs of characters sharing the same font.
 *
 * Shared by GdLabelCanvas and ImagickLabelCanvas so both backends pick
 * fonts identically. The primary font (DejaVu Sans Bold) covers Latin
 * (incl. diacritics), Cyrillic, Greek, and similar scripts natively;
 * this routes characters it doesn't cover to a fallback font instead
 * of leaving them blank or garbled - CJK ideographs/kana/hangul to a
 * CJK font, and emoji (astral-plane codepoints) to a dedicated
 * monochrome emoji font. A name mixing scripts (e.g. "Wang 王") is
 * split into multiple runs, each drawn/measured in its own font and
 * laid out left-to-right in sequence.
 *
 * Known limitation: right-to-left/contextually-shaped scripts (Arabic,
 * Hebrew, etc.) are not supported and will render left-to-right with
 * disconnected letterforms. This is a limitation of the underlying
 * drawing APIs this class calls (GD's `imagettftext()`, Imagick's
 * `ImagickDraw::annotation()`), not something introduced by this run-
 * splitting logic itself - verified by testing each API completely
 * bare, with no font-fallback/run-splitting involved at all, and
 * seeing the identical unshaped, left-to-right result. Proper support
 * would need routing Imagick's text rendering through Pango (available
 * as a compiled-in ImageMagick delegate here, confirmed working for
 * Arabic in testing), which has no GD equivalent at all and is a
 * meaningfully larger, separate effort - deferred as low-priority
 * pending an actual need for RTL script support.
 */
trait SplitsFontRunsTrait {

  /**
   * Whether a codepoint is CJK ideographs/kana/hangul/punctuation.
   *
   * Range-based rather than querying actual glyph coverage - simple,
   * and sufficient for the specific, known gap in DejaVu Sans Bold's
   * coverage (it has no CJK glyphs at all, so there's no ambiguous
   * boundary to get precisely right here). Also used by
   * `LabelRenderer::tokenizeName()` to decide where a name may be
   * line-broken - CJK text conventionally has no spaces between
   * words/characters at all, so word-boundary-only breaking (as used
   * for Latin scripts) would never find a break point in it.
   */
  protected function isCjkCodepoint(int $codepoint): bool {
    // CJK Unified Ideographs (+ Extension A), Hiragana/Katakana, Hangul
    // Syllables, CJK Symbols and Punctuation, Halfwidth/Fullwidth Forms.
    return ($codepoint >= 0x4E00 && $codepoint <= 0x9FFF)
      || ($codepoint >= 0x3400 && $codepoint <= 0x4DBF)
      || ($codepoint >= 0x3040 && $codepoint <= 0x30FF)
      || ($codepoint >= 0xAC00 && $codepoint <= 0xD7A3)
      || ($codepoint >= 0x3000 && $codepoint <= 0x303F)
      || ($codepoint >= 0xFF00 && $codepoint <= 0xFFEF);
  }

  /**
   * Picks a font path for one character, by Unicode block.
   */
  protected function fontPathForChar(string $char, string $defaultFont, string $cjkFont, string $emojiFont): string {
    $codepoint = mb_ord($char) ?: 0;

    if ($this->isCjkCodepoint($codepoint)) {
      return $cjkFont;
    }

    // Astral-plane codepoints are almost entirely emoji (plus a
    // handful of obscure historic scripts DejaVu doesn't cover either,
    // which will simply draw as the emoji font's own missing-glyph
    // fallback - an acceptable edge case).
    if ($codepoint >= 0x10000) {
      return $emojiFont;
    }

    return $defaultFont;
  }

  /**
   * Splits `$text` into [fontPath, substring] runs.
   *
   * Consecutive characters sharing the same font are grouped into one
   * run, so plain single-script text (the common case) yields exactly
   * one run - no different from measuring/drawing the whole string
   * directly.
   *
   * @return array<array{0: string, 1: string}>
   *   A list of [fontPath, substring] tuples, in original text order.
   */
  protected function splitIntoFontRuns(string $text, string $defaultFont, string $cjkFont, string $emojiFont): array {
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $runs = [];
    $currentFont = NULL;
    $currentText = '';
    foreach ($chars as $char) {
      $font = $this->fontPathForChar($char, $defaultFont, $cjkFont, $emojiFont);
      if ($currentFont !== NULL && $font !== $currentFont) {
        $runs[] = [$currentFont, $currentText];
        $currentText = '';
      }
      $currentFont = $font;
      $currentText .= $char;
    }
    if ($currentText !== '') {
      $runs[] = [$currentFont, $currentText];
    }

    return $runs ?: [[$defaultFont, $text]];
  }

}
