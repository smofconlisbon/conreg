<?php

declare(strict_types=1);

namespace Drupal\conreg;

/**
 * Converts between admin textarea strings and clean line arrays.
 *
 * Several conreg config values store "one item per line" lists. Textareas
 * always submit with CRLF line endings; this class centralizes the
 * string -> array normalization (used by form submitForm() and by the
 * update hook that migrates existing config) so it isn't duplicated per
 * field.
 */
final class TextareaLines {

  /**
   * Splits a submitted textarea string into a clean array of lines.
   *
   * Splits on \r\n, \r, or \n, trims each line, and drops empty lines.
   * Already-array input is normalized the same way, so this is idempotent
   * when called on already-migrated config.
   *
   * @param string|array|null $value
   *   The raw textarea string (or an already-converted array).
   *
   * @return string[]
   *   Clean, non-empty, trimmed lines, reindexed from 0.
   */
  public static function toArray(string|array|null $value): array {
    if ($value === NULL || $value === '') {
      return [];
    }
    $lines = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', $value);
    $lines = array_map('trim', $lines);
    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
  }

  /**
   * Builds a textarea #default_value string from a stored line array.
   *
   * @param string[]|string|null $value
   *   The stored config value (array in the new format; a raw string is
   *   also accepted so this is safe to call before the update hook runs).
   *
   * @return string
   *   Newline-joined string suitable for a textarea #default_value.
   */
  public static function toTextareaString(array|string|null $value): string {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return $value ?? '';
  }

}
