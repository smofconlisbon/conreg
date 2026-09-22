<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Admin\MemberDelete;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the admin "Delete member" confirmation form (#3596650).
 *
 * The badge_name, payment_id and comment columns of conreg_members are
 * nullable, and rows migrated from older events hold SQL NULL in them.
 * MemberDelete::buildForm() passes those values straight into t()
 * placeholders. Placeholder values are escaped with Html::escape(), which
 * takes a string, so the form throws a TypeError when it is rendered and
 * the admin sees "The website encountered an unexpected error".
 */
#[CoversClass(MemberDelete::class)]
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MemberDeleteFormKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * Set up database tables and config for testing the form.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'datetime']);
    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_options',
    ]);
    $this->installConfig(['conreg']);

    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();
  }

  /**
   * Inserts a member row, letting $overrides replace any default column.
   */
  protected function createTestMember(array $overrides = []): int {
    $fields = $overrides + [
      'mid' => 1,
      'eid' => 1,
      'lead_mid' => 1,
      'language' => 'en',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'badge_name' => 'Jane Doe',
      'email' => 'jane.doe@example.com',
      'payment_id' => 'pi_test',
      'comment' => 'A comment',
      'is_deleted' => 0,
      'join_date' => \Drupal::time()->getCurrentTime(),
      'update_date' => \Drupal::time()->getCurrentTime(),
    ];

    return (int) Database::getConnection()
      ->insert('conreg_members')
      ->fields($fields)
      ->execute();
  }

  /**
   * Builds the form for member $mid and renders it to an HTML string.
   *
   * The TypeError is only raised when the lazy t() strings are cast to
   * string, which happens during rendering rather than in buildForm().
   */
  protected function renderDeleteForm(int $mid): string {
    $form = MemberDelete::create($this->container)
      ->buildForm([], new FormState(), 1, $mid);

    return (string) $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $this->container->get('renderer')->render($form),
    );
  }

  /**
   * The form renders when a member has fully populated fields.
   *
   * Control case: proves the harness itself works, so a failure in the
   * NULL cases below is down to the NULL value and not the test setup.
   */
  public function testFormRendersForMemberWithAllFieldsPopulated(): void {
    $mid = $this->createTestMember();

    $html = $this->renderDeleteForm($mid);

    $this->assertStringContainsString('Comment: A comment', $html);
    $this->assertStringContainsString('Payment reference: pi_test', $html);
  }

  /**
   * The form renders when a nullable column holds SQL NULL.
   *
   * @param string $column
   *   The nullable conreg_members column to set to NULL.
   * @param string $label
   *   The label the form shows for that column.
   */
  #[DataProvider('nullableColumnProvider')]
  public function testFormRendersWhenNullableColumnIsNull(string $column, string $label): void {
    $mid = $this->createTestMember([$column => NULL]);

    $html = $this->renderDeleteForm($mid);

    // The field is shown with a blank value rather than being dropped.
    $this->assertStringContainsString($label . ':', $html);
  }

  /**
   * Data provider for testFormRendersWhenNullableColumnIsNull().
   *
   * @return array<string, array{string, string}>
   *   Column name and displayed label, keyed by column name.
   */
  public static function nullableColumnProvider(): array {
    return [
      'comment' => ['comment', 'Comment'],
      'badge_name' => ['badge_name', 'Badge Name'],
      'payment_id' => ['payment_id', 'Payment reference'],
    ];
  }

  /**
   * A member with NULL in every nullable column can still be deleted.
   *
   * This is the end-to-end scenario from the bug report: open the confirm
   * screen, then submit it.
   */
  public function testMemberWithNullColumnsCanBeDeleted(): void {
    $mid = $this->createTestMember([
      'badge_name' => NULL,
      'payment_id' => NULL,
      'comment' => NULL,
    ]);

    $formObject = MemberDelete::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState, 1, $mid);
    $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $this->container->get('renderer')->render($form),
    );

    $formObject->submitForm($form, $formState);

    $isDeleted = Database::getConnection()
      ->select('conreg_members', 'm')
      ->fields('m', ['is_deleted'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $isDeleted);
  }

}
