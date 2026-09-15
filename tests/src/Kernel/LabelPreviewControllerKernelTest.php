<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Controller\LabelPreviewController;
use Drupal\conreg\Entity\LabelSize;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests LabelPreviewController, the on-demand settings-page preview.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class LabelPreviewControllerKernelTest extends KernelTestBase {

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
   * Call the controller the same way the route would.
   */
  protected function callController(): LabelPreviewController {
    return LabelPreviewController::create($this->container);
  }

  /**
   * Test posting explicit field values/layout renders a decodable PNG.
   */
  public function testPreviewRendersDecodableImage(): void {
    LabelSize::create([
      'id' => 'preview_test_size',
      'label' => 'Preview test size',
      'width_mm' => 28,
      'height_mm' => 89,
      'rotate_degrees' => 90,
    ])->save();

    $body = json_encode([
      'label_size' => 'preview_test_size',
      'name_lines' => 2,
      'field_positions' => [
        'badge_name' => 'middle',
        'member_number' => 'bottom_left',
        'days_attending' => 'bottom_right',
        'badge_type' => 'none',
      ],
      'fields' => [
        'badge_name' => 'Jane Doe',
        'member_number' => 'M-4021',
        'days_attending' => 'Fri-Sun',
      ],
    ]);
    $request = Request::create('/admin/config/conreg/label-printing/preview', 'POST', [], [], [], [], $body);

    $response = $this->callController()->preview($request);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringStartsWith('data:image/png;base64,', $data['image_data_uri']);
    $decoded = base64_decode(substr($data['image_data_uri'], strlen('data:image/png;base64,')), TRUE);
    $this->assertNotFalse($decoded);
    $this->assertNotFalse(imagecreatefromstring($decoded));
  }

  /**
   * Test that omitting everything falls back to the saved settings.
   *
   * Previewing with no body at all previews "as saved."
   */
  public function testPreviewFallsBackToSavedSettings(): void {
    LabelSize::create([
      'id' => 'preview_default_size',
      'label' => 'Preview default size',
      'width_mm' => 28,
      'height_mm' => 89,
      'rotate_degrees' => 90,
    ])->save();

    $this->container->get('config.factory')
      ->getEditable('conreg.label_printing.settings')
      ->set('label_size', 'preview_default_size')
      ->set('name_lines', 2)
      ->set('field_positions', [
        'badge_name' => 'middle',
        'member_number' => 'bottom_left',
        'days_attending' => 'bottom_right',
        'badge_type' => 'none',
      ])
      ->save();

    $request = Request::create('/admin/config/conreg/label-printing/preview', 'POST', [], [], [], [], '{}');
    $response = $this->callController()->preview($request);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Test that no label size configured or given is rejected cleanly.
   */
  public function testPreviewRejectsWhenNoLabelSizeAvailable(): void {
    $request = Request::create('/admin/config/conreg/label-printing/preview', 'POST', [], [], [], [], '{}');
    $response = $this->callController()->preview($request);

    $this->assertSame(400, $response->getStatusCode());
  }

}
