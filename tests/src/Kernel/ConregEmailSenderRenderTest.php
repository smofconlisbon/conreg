<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Service\ConregEmailSender;
use Drupal\easy_email\Entity\EasyEmailInterface;
use Drupal\Core\Database\Database;
use Drupal\easy_email\Entity\EasyEmailType;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore mailsystem's

/**
 * Tests conreg emails through the real text-format filter pipeline.
 *
 * EmailTokenContextTest only exercises \Drupal::token()->replace()
 * directly - it never runs the resolved HTML body through the configured
 * text format's filters the way Easy Email's own
 * template_preprocess_easy_email_body_html() does (#type =>
 * processed_text, i.e. check_markup()). A regression where the format
 * strips markup (e.g. 'basic_html''s allowed-tags list silently
 * discarding [conreg:member-details]'s <table>) is invisible to
 * token-only tests - this class catches that class of bug instead, by
 * applying the format's filters directly.
 *
 * It deliberately does not go through EmailHandler::preview()/
 * sendEmail() - which mail plugin actually transports the message there
 * depends on mailsystem's per-module config (symfony_mailer_lite for
 * 'easy_email' on this site, which preserves HTML; core's own php_mail
 * plugin, active by default in a bare Kernel test, additionally
 * converts everything to plain text via MailFormatHelper::htmlToText()
 * regardless of the format used - a separate, transport-level concern
 * from the filter-stripping bug this test targets).
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregEmailSenderRenderTest extends KernelTestBase {

  /**
   * Modules to enable for this test.
   *
   * @var array
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
   * Set up database tables and config for building an email.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('easy_email');
    $this->installEntitySchema('file');
    $this->installConfig(['system', 'datetime', 'filter', 'easy_email']);

    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
    ]);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();
    Database::getConnection()->insert('conreg_members')
      ->fields([
        'mid' => 1,
        'eid' => 1,
        'lead_mid' => 1,
        'language' => 'en',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane.doe@example.com',
        'join_date' => \Drupal::time()->getCurrentTime(),
        'update_date' => \Drupal::time()->getCurrentTime(),
      ])
      ->execute();

    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML'])->save();
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => [
        'filter_html' => [
          'id' => 'filter_html',
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p> <h3>'],
        ],
      ],
    ])->save();
  }

  /**
   * The member-details table survives the text format's filters.
   *
   * This is the regression test for the bug itself: after resolving
   * [conreg:member-details]'s tokens (producing a real <table>), running
   * that HTML through check_markup() with the full_html format - exactly
   * what template_preprocess_easy_email_body_html()'s #type =>
   * processed_text does - must still contain the table.
   */
  public function testMemberDetailsTableSurvivesFullHtmlFilter(): void {
    $email = $this->buildEmailWithMemberDetailsBody('conreg_reg_render_test', 'full_html');
    $htmlBody = $email->getHtmlBody();
    $resolved = \Drupal::token()->replace($htmlBody['value'], ['easy_email' => $email]);

    $filtered = $this->applyTextFormat($resolved, 'full_html');

    $this->assertStringContainsString('<table', $filtered);
    $this->assertStringContainsString('Jane', $filtered);
  }

  /**
   * Documents the bug this test file exists to catch.
   *
   * A restrictive format's allowed-tags list (e.g. 'basic_html' without
   * <table>/<tr>/<td> in its allowed_html) silently strips the member
   * details table down to run-on text - conreg's own templates must never
   * use such a format (see conreg_update_9012), but this proves the
   * failure mode is real and would be caught if one did.
   */
  public function testMemberDetailsTableStrippedByRestrictiveFormat(): void {
    $email = $this->buildEmailWithMemberDetailsBody('conreg_reg_restrict_test', 'basic_html');
    $htmlBody = $email->getHtmlBody();
    $resolved = \Drupal::token()->replace($htmlBody['value'], ['easy_email' => $email]);

    $filtered = $this->applyTextFormat($resolved, 'basic_html');

    $this->assertStringNotContainsString('<table', $filtered);
  }

  /**
   * Applies a text format's filters, the same way #type => processed_text does.
   *
   * Used by template_preprocess_easy_email_body_html().
   */
  protected function applyTextFormat(string $text, string $format): string {
    $build = [
      '#type' => 'processed_text',
      '#text' => $text,
      '#format' => $format,
    ];
    return (string) \Drupal::service('renderer')->renderInIsolation($build);
  }

  /**
   * Builds a transient email of a fresh bundle whose body is just the token.
   *
   * So its raw (unresolved) HTML body can be pulled back out and run
   * through the text format's filters directly.
   */
  protected function buildEmailWithMemberDetailsBody(string $bundleId, string $format): EasyEmailInterface {
    EasyEmailType::create([
      'id' => $bundleId,
      'label' => $bundleId,
      'subject' => 'Test',
      'bodyHtml' => ['value' => '[conreg:member-details]', 'format' => $format],
      'generateBodyPlain' => FALSE,
    ])->save();

    return \Drupal::service(ConregEmailSender::class)
      ->build($bundleId, 'jane.doe@example.com', 1, [1]);
  }

}
