<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Controller\CheckInLabelPreviewController;
use Drupal\conreg\Entity\LabelSize;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests CheckInLabelPreviewController, the "Preview label" modal content.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class CheckInLabelPreviewControllerKernelTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('conreg', ['conreg_members', 'conreg_events']);
    $this->installConfig(['conreg']);

    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Test event', 'is_open' => 1])
      ->execute();
  }

  /**
   * Helper function to create a test member.
   */
  protected function createTestMember(array $overrides = []): int {
    $defaults = [
      'mid' => 1,
      'eid' => 1,
      'language' => 'en',
      'first_name' => 'Test',
      'last_name' => 'User',
      'badge_name' => 'Jane Doe',
      'member_no' => 4021,
      'days' => 'Fr|Sa|Su',
      'email' => 'test@example.com',
    ];

    $fields = $overrides + $defaults;

    return Database::getConnection()
      ->insert('conreg_members')
      ->fields($fields)
      ->execute();
  }

  /**
   * Call the controller the same way the route would.
   */
  protected function callController(): CheckInLabelPreviewController {
    return CheckInLabelPreviewController::create($this->container);
  }

  /**
   * Test that no label size configured shows a message, not an image.
   *
   * The module's default config already ships a label_size (see
   * config/install/conreg.label_printing.settings.yml), so this clears
   * it explicitly to exercise the "no label size" path.
   */
  public function testBuildShowsMessageWhenNoLabelSizeConfigured(): void {
    $this->config('conreg.label_printing.settings')->set('label_size', '')->save();

    $mid = $this->createTestMember();

    $build = $this->callController()->build(1, $mid);

    $this->assertArrayHasKey('message', $build);
    $this->assertArrayNotHasKey('image', $build);
    $this->assertSame('link', $build['actions']['close']['#type']);
    $this->assertContains('dialog-cancel', $build['actions']['close']['#attributes']['class']);
  }

  /**
   * Test that a configured label size renders an <img> with a data URI.
   */
  public function testBuildShowsImageWhenLabelSizeConfigured(): void {
    LabelSize::create([
      'id' => 'checkin_preview_test_size',
      'label' => 'Check-in preview test size',
      'width_mm' => 28,
      'height_mm' => 89,
      'rotate_degrees' => 90,
    ])->save();
    $this->config('conreg.label_printing.settings')
      ->set('label_size', 'checkin_preview_test_size')
      ->set('name_lines', 2)
      ->set('field_positions', [
        'badge_name' => 'middle',
        'member_number' => 'bottom_left',
        'days_attending' => 'bottom_right',
        'badge_type' => 'none',
      ])
      ->save();

    $mid = $this->createTestMember();

    $build = $this->callController()->build(1, $mid);

    $this->assertArrayNotHasKey('message', $build);
    $this->assertSame('img', $build['image']['#tag']);
    $this->assertStringStartsWith('data:image/png;base64,', $build['image']['#attributes']['src']);
    $this->assertContains('conreg/conreg_form', $build['#attached']['library']);
  }

  /**
   * Building for a member of a different event is rejected.
   *
   * Regression test: this route's {eid} was previously only used for
   * the "Close" link, never checked against the member's own event -
   * see AssertMemberEventTrait.
   */
  public function testBuildThrowsForMemberOfDifferentEvent(): void {
    Database::getConnection()->insert('conreg_events')
      ->fields(['event_name' => 'Other event', 'is_open' => 1])
      ->execute();
    $mid = $this->createTestMember(['eid' => 2]);

    $this->expectException(NotFoundHttpException::class);
    $this->callController()->build(1, $mid);
  }

}
