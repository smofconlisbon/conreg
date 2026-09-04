<?php

declare(strict_types=1);

namespace Drupal\conreg;

use Drupal\Component\Utility\Html;

/**
 * Builds consistent, theme-able attributes for ConReg's #type => table.
 */
class ConregTable {

  /**
   * Builds the `#attributes` array for a `#type => table` render element.
   *
   * @param string $idSuffix
   *   A short, human-readable identifier for this table (e.g.
   *   'member-summary'). Combined with a shared prefix and deduplicated,
   *   so it does not need to be unique on its own.
   * @param \Drupal\conreg\TableRole $role
   *   The table's structural role, exposed as a `conreg-table--<role>`
   *   class so themes can style by role rather than by page position.
   * @param string[] $extraClasses
   *   Any additional classes to attach.
   *
   * @return array{id: string, class: string[]}
   *   An `#attributes` array with a unique `id` and role-based classes.
   */
  public static function attributes(string $idSuffix, TableRole $role, array $extraClasses = []): array {
    return [
      'id' => Html::getUniqueId('conreg-table-' . $idSuffix),
      'class' => ['conreg-table', 'conreg-table--' . $role->value, ...$extraClasses],
    ];
  }

  /**
   * Builds a `#footer` array for a single "Total" row.
   *
   * Wraps each cell value with the `table-total` styling class, in the
   * `#footer` shape Drupal's table theming expects (an array containing
   * one row, itself an array of cells). Array keys are preserved, so a
   * `#header`/`#rows` pair keyed by column name (e.g. `'type'`,
   * `'number'`) can be matched by passing the same keys here.
   *
   * Not a fit for a cell that needs attributes beyond the styling class
   * (e.g. a `colspan`) - build that one row by hand instead.
   *
   * @param array $cells
   *   The total row's cell values, in column order (or keyed by column
   *   name to match `#header`/`#rows`).
   *
   * @return array
   *   A `#footer` array containing the one total row.
   */
  public static function totalFooterRow(array $cells): array {
    return [
      array_map(
        fn($value) => ['data' => $value, 'class' => ['table-total']],
        $cells
      ),
    ];
  }

}
