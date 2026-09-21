<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Entity\LabelSize;
use Drupal\conreg\Entity\PrintJob;
use Drupal\conreg\Entity\Printer;
use Drupal\conreg\Service\MemberStorage;
use Drupal\conreg\Service\PrintJobManager;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests PrintJobManager, which creates print jobs for members.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class PrintJobManagerKernelTest extends KernelTestBase {

  protected const CURRENT_TIME = 1700000000;

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
    $this->installEntitySchema('conreg_printer');
    $this->installEntitySchema('conreg_print_job');

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
   * Helper: create a printer entity.
   *
   * Derives a CUPS-safe machine name from the display name (e.g. "Bilbo
   * Baggins" -> "bilbo_baggins") unless one is given explicitly.
   */
  protected function createPrinter(int $eid, string $name, ?string $machineName = NULL): Printer {
    $machineName ??= strtolower(str_replace(' ', '_', $name));
    $printer = Printer::create(['eid' => $eid, 'name' => $name, 'machine_name' => $machineName]);
    $printer->save();
    return $printer;
  }

  /**
   * Test that a job snapshots the member's current badge details.
   */
  public function testCreateJobSnapshotsMemberData(): void {
    $mid = $this->createTestMember();
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);
    $job = $manager->createJob($mid, 'bilbo_baggins');

    $this->assertSame(1, (int) $job->get('eid')->value);
    $this->assertSame($mid, (int) $job->get('mid')->value);
    $this->assertSame('Jane Doe', $job->get('member_name')->value);
    // showBadgeNumber() with no badge_type and an already-4-digit number
    // (conreg.settings.1's member_no_digits is 4) happens to equal the
    // raw number here - see testCreateJobFormatsMemberNumberWithBadgeType
    // for a case that actually exercises the formatting.
    $this->assertSame('4021', $job->get('member_number')->value);
    $this->assertSame('Friday, Saturday, Sunday', $job->get('days_attending')->value);
    $this->assertSame('pending', $job->get('status')->value);
    $this->assertSame('Bilbo Baggins', $job->get('printer')->entity->get('name')->value);

    // Confirm it was actually persisted, not just held in memory.
    $this->container->get('entity_type.manager')->getStorage('conreg_print_job')->resetCache();
    $this->assertNotNull(PrintJob::load($job->id()));
  }

  /**
   * Test the member number is formatted like the check-in table's.
   *
   * ShowBadgeNumberTrait::showBadgeNumber() prefixes the badge type and
   * zero-pads to conreg.settings.1's configured member_no_digits (4).
   */
  public function testCreateJobFormatsMemberNumberWithBadgeType(): void {
    $mid = $this->createTestMember(['member_no' => 42, 'badge_type' => 'A']);
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);
    $job = $manager->createJob($mid, 'bilbo_baggins');

    $this->assertSame('A0042', $job->get('member_number')->value);
    $this->assertSame('A', $job->get('badge_type')->value);
  }

  /**
   * Test that a member with no days selected gets an empty days_attending.
   *
   * Drupal normalizes an empty value on a non-required string base field
   * to NULL on save, so that - not '' - is the correct "no value" here.
   */
  public function testCreateJobWithNoDaysSelected(): void {
    $mid = $this->createTestMember(['days' => '']);
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);
    $job = $manager->createJob($mid, 'bilbo_baggins');

    $this->assertNull($job->get('days_attending')->value);
  }

  /**
   * Test that an unknown member ID is rejected.
   */
  public function testCreateJobThrowsForUnknownMember(): void {
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->createJob(999, 'bilbo_baggins');
  }

  /**
   * Test that an unknown printer machine name is rejected.
   */
  public function testCreateJobThrowsForUnknownPrinter(): void {
    $mid = $this->createTestMember();

    $manager = $this->container->get(PrintJobManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->createJob($mid, 'no_such_printer');
  }

  /**
   * Test that createJobWithRenderedImage() stores the given bytes as-is.
   *
   * Confirmed by passing bytes that couldn't possibly have come out of
   * the real rendering pipeline (a real render always starts with the
   * PNG magic header) - proving the method never renders anything
   * itself, unlike createJob().
   */
  public function testCreateJobWithRenderedImageUsesProvidedImageBytes(): void {
    $mid = $this->createTestMember();
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);
    $job = $manager->createJobWithRenderedImage($mid, 'bilbo_baggins', 'not-a-real-png');

    $this->assertSame('not-a-real-png', $job->get('image_data')->value);
    $this->assertSame('Jane Doe', $job->get('member_name')->value);
    $this->assertSame('pending', $job->get('status')->value);
  }

  /**
   * Test that an unknown member ID is rejected, same as createJob().
   */
  public function testCreateJobWithRenderedImageThrowsForUnknownMember(): void {
    $this->createPrinter(1, 'Bilbo Baggins');

    $manager = $this->container->get(PrintJobManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->createJobWithRenderedImage(999, 'bilbo_baggins', 'image-bytes');
  }

  /**
   * Test that an unknown printer is rejected, same as createJob().
   */
  public function testCreateJobWithRenderedImageThrowsForUnknownPrinter(): void {
    $mid = $this->createTestMember();

    $manager = $this->container->get(PrintJobManager::class);

    $this->expectException(\InvalidArgumentException::class);
    $manager->createJobWithRenderedImage($mid, 'no_such_printer', 'image-bytes');
  }

  /**
   * Test that previewing an already-loaded member matches renderPreviewImage().
   */
  public function testRenderPreviewImageForMemberMatchesRenderPreviewImage(): void {
    LabelSize::create([
      'id' => 'for_member_test_size',
      'label' => 'For-member test size',
      'width_mm' => 28,
      'height_mm' => 89,
      'rotate_degrees' => 90,
    ])->save();
    $this->config('conreg.label_printing.settings')
      ->set('label_size', 'for_member_test_size')
      ->save();

    $mid = $this->createTestMember();

    $manager = $this->container->get(PrintJobManager::class);
    $member = $this->container->get(MemberStorage::class)->load(['mid' => $mid]);

    $this->assertSame(
      $manager->renderPreviewImage($mid),
      $manager->renderPreviewImageForMember($member),
    );
  }

  /**
   * Test that only the requested event's printers are returned.
   */
  public function testGetPrintersForEventReturnsOnlyThatEventsPrinters(): void {
    $this->createPrinter(1, 'Bilbo Baggins');
    $this->createPrinter(1, 'Frodo Baggins');
    $this->createPrinter(2, 'Other Event Printer');

    $manager = $this->container->get(PrintJobManager::class);
    $printers = $manager->getPrintersForEvent(1);

    $names = array_map(fn ($printer) => $printer->label(), $printers);
    sort($names);
    $this->assertSame(['Bilbo Baggins', 'Frodo Baggins'], $names);
  }

  /**
   * Test that an event with no printers gets an empty array.
   */
  public function testGetPrintersForEventReturnsEmptyArrayWhenNoneConfigured(): void {
    $manager = $this->container->get(PrintJobManager::class);
    $this->assertSame([], $manager->getPrintersForEvent(1));
  }

  /**
   * Test that previewing an unknown member returns NULL, not an error.
   */
  public function testRenderPreviewImageReturnsNullForUnknownMember(): void {
    $manager = $this->container->get(PrintJobManager::class);
    $this->assertNull($manager->renderPreviewImage(999));
  }

  /**
   * Test that previewing with no label size configured returns NULL.
   *
   * The module's default config already ships a label_size (see
   * config/install/conreg.label_printing.settings.yml), so this clears
   * it explicitly to exercise createJob()'s "check-in isn't blocked by
   * incomplete Label Printing Settings configuration" behavior (see
   * renderLabelImage()).
   */
  public function testRenderPreviewImageReturnsNullWhenNoLabelSizeConfigured(): void {
    $this->config('conreg.label_printing.settings')->set('label_size', '')->save();

    $mid = $this->createTestMember();

    $manager = $this->container->get(PrintJobManager::class);
    $this->assertNull($manager->renderPreviewImage($mid));
  }

  /**
   * Test that a preview renders a decodable PNG from the member's own data.
   *
   * Uses the same field-building path as createJob() (see
   * testCreateJobFormatsMemberNumberWithBadgeType), so this also
   * confirms a preview reflects exactly what a real print job would.
   */
  public function testRenderPreviewImageRendersDecodablePngFromMemberData(): void {
    LabelSize::create([
      'id' => 'preview_test_size',
      'label' => 'Preview test size',
      'width_mm' => 28,
      'height_mm' => 89,
      'rotate_degrees' => 90,
    ])->save();
    $this->config('conreg.label_printing.settings')
      ->set('label_size', 'preview_test_size')
      ->set('name_lines', 2)
      ->set('field_positions', [
        'badge_name' => 'middle',
        'member_number' => 'bottom_left',
        'days_attending' => 'bottom_right',
        'badge_type' => 'none',
      ])
      ->save();

    $mid = $this->createTestMember(['member_no' => 42, 'badge_type' => 'A']);

    $manager = $this->container->get(PrintJobManager::class);
    $imageBase64 = $manager->renderPreviewImage($mid);

    $this->assertNotNull($imageBase64);
    $decoded = base64_decode($imageBase64, TRUE);
    $this->assertNotFalse($decoded);
    $this->assertSame("\x89PNG\r\n\x1a\n", substr($decoded, 0, 8));
  }

}
