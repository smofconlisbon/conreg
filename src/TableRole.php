<?php

declare(strict_types=1);

namespace Drupal\conreg;

/**
 * The structural role a ConReg table plays, for theming purposes.
 *
 * Lets a theme style a table by what it actually is (a plain list vs. a
 * summary with totals), rather than having to guess from where it happens
 * to sit on the page.
 */
enum TableRole: string {
  case ListTable = 'list';
  case Summary = 'summary';
}
