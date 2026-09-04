<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit;

use Drupal\conreg\ConregTable;
use Drupal\conreg\TableRole;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ConregTable::attributes().
 */
#[CoversClass(ConregTable::class)]
#[Group('conreg')]
class ConregTableTest extends UnitTestCase {

  /**
   * The id carries a shared prefix and a role-based modifier class.
   */
  public function testAttributesCarryPrefixedIdAndRoleClass(): void {
    $attributes = ConregTable::attributes('member-summary', TableRole::Summary);

    $this->assertSame('conreg-table-member-summary', $attributes['id']);
    $this->assertSame(['conreg-table', 'conreg-table--summary'], $attributes['class']);
  }

  /**
   * Extra classes are appended after the standard ones.
   */
  public function testExtraClassesAreAppended(): void {
    $attributes = ConregTable::attributes('member-unpaid', TableRole::ListTable, ['conreg-table--striped']);

    $this->assertSame(
      ['conreg-table', 'conreg-table--list', 'conreg-table--striped'],
      $attributes['class']
    );
  }

  /**
   * Two tables asking for the same id suffix get distinct, valid ids.
   *
   * This is the actual fix for the duplicate-id bug the helper replaces:
   * two hardcoded literal ids on the same page were invalid HTML.
   */
  public function testRepeatedIdSuffixIsDeduplicated(): void {
    $first = ConregTable::attributes('member-list', TableRole::ListTable);
    $second = ConregTable::attributes('member-list', TableRole::ListTable);

    $this->assertNotSame($first['id'], $second['id']);
    $this->assertStringStartsWith('conreg-table-member-list', $second['id']);
  }

  /**
   * A total row wraps each cell with the table-total class, in order.
   */
  public function testTotalFooterRowWrapsEachCell(): void {
    $footer = ConregTable::totalFooterRow(['Total', 42]);

    $this->assertSame(
      [
        [
          ['data' => 'Total', 'class' => ['table-total']],
          ['data' => 42, 'class' => ['table-total']],
        ],
      ],
      $footer
    );
  }

  /**
   * Column-name keys are preserved, to match a keyed #header/#rows pair.
   */
  public function testTotalFooterRowPreservesKeys(): void {
    $footer = ConregTable::totalFooterRow(['type' => 'Total', 'number' => 7]);

    $this->assertSame(
      [
        [
          'type' => ['data' => 'Total', 'class' => ['table-total']],
          'number' => ['data' => 7, 'class' => ['table-total']],
        ],
      ],
      $footer
    );
  }

}
