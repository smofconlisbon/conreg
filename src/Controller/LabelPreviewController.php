<?php

declare(strict_types=1);

namespace Drupal\conreg\Controller;

use Drupal\conreg\Service\LabelRenderer;
use Drupal\conreg\Service\PrintJobManager;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders an on-demand label preview for the Label Printing Settings page.
 *
 * Session/permission-authenticated (not the print agent's API-key
 * auth) - this is for an admin previewing settings in their browser,
 * not a print-server device. Deliberately reusable beyond the settings
 * page: it accepts arbitrary field values rather than assuming "the
 * saved settings," so a future check-in-screen preview button is a
 * thin reuse of this same endpoint, not new plumbing.
 */
class LabelPreviewController extends ControllerBase {

  public function __construct(
    protected LabelRenderer $labelRenderer,
    protected PrintJobManager $printJobManager,
  ) {}

  /**
   * Renders a preview label from posted (possibly unsaved) settings.
   *
   * Accepts a JSON body with any of: label_size (entity ID),
   * field_positions (array), name_lines (int), and sample field values
   * under `fields` (badge_name/member_number/days_attending/
   * badge_type). Anything omitted falls back to the currently *saved*
   * settings, so posting an empty body previews the saved
   * configuration as-is.
   */
  public function preview(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE) ?? [];

    $config = $this->config('conreg.label_printing.settings');

    $labelSizeId = $data['label_size'] ?? $config->get('label_size');
    if (!$labelSizeId) {
      return new JsonResponse(['error' => 'No label size selected.'], 400);
    }

    /** @var \Drupal\conreg\Entity\LabelSize|null $labelSize */
    $labelSize = $this->entityTypeManager()->getStorage('conreg_label_size')->load($labelSizeId);
    if (!$labelSize) {
      return new JsonResponse(['error' => 'Unknown label size.'], 400);
    }

    $fieldPositions = $data['field_positions'] ?? ($config->get('field_positions') ?: []);
    $nameLines = (int) ($data['name_lines'] ?? ($config->get('name_lines') ?: 2));

    $fields = $data['fields'] ?? [
      'badge_name' => 'Jane Doe',
      'member_number' => 'M-4021',
      'days_attending' => 'Fri-Sun',
      'badge_type' => 'Adult',
    ];

    $png = $this->labelRenderer->render($fields, $labelSize, $fieldPositions, $nameLines);

    return new JsonResponse([
      'image_data_uri' => 'data:image/png;base64,' . base64_encode($png),
    ]);
  }

  /**
   * Queues a "test print" job sending exactly a previously-rendered preview.
   *
   * Accepts a JSON body with `printer` (a conreg_printer entity ID),
   * `image_data_uri` (the data URI from a prior preview() response -
   * not re-rendered here, so what was previewed is exactly what
   * prints), and optionally `fields` (stored on the job for reference).
   * Queues a real conreg_print_job; there is no way for ConReg to print
   * synchronously, since print-server devices are outbound-only pollers
   * by design, so the next agent poll of that printer will print it.
   */
  public function testPrint(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE) ?? [];

    $printerId = $data['printer'] ?? NULL;
    if (!$printerId) {
      return new JsonResponse(['error' => 'No printer selected.'], 400);
    }

    $imageDataUri = $data['image_data_uri'] ?? '';
    if (!preg_match('/^data:image\/png;base64,(.+)$/', $imageDataUri, $matches)) {
      return new JsonResponse(['error' => 'No preview image to print - click Preview first.'], 400);
    }

    try {
      $job = $this->printJobManager->createTestJob($data['fields'] ?? [], (int) $printerId, $matches[1]);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 400);
    }

    return new JsonResponse([
      'queued' => TRUE,
      'job_id' => $job->id(),
    ]);
  }

}
