<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Service\ConregEmailSender;
use Drupal\Core\Database\Database;
use Drupal\easy_email\Entity\EasyEmailType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore Unconfigured

/**
 * Tests that ConregEmailSender::build() picks the recipient's own language.
 *
 * Regression coverage for the bug documented in architecture/email.md and
 * the project's implementation plan: neither MailManager::doMail() nor Easy
 * Email's own EmailHandler::sendEmail() ever select a non-default language,
 * so the fix has to live at the ConReg call-site layer - build() wraps
 * EmailHandler::createEmail() in
 * LanguageManagerInterface::setConfigOverrideLanguage() so a translated
 * EasyEmailType's text is actually copied onto the entity. Before this
 * fix, Config Translation entries were captured but never used - this test
 * proves the argument actually changes which text gets used, not just that
 * the plumbing runs without error.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregEmailSenderLanguageTest extends KernelTestBase {

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
    'language',
  ];

  /**
   * Set up database tables and config, plus an English/French template.
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

    EasyEmailType::create([
      'id' => 'conreg_registration_test',
      'label' => 'ConReg registration (test)',
      'subject' => 'Hello',
      'bodyHtml' => ['value' => '<p>Hello</p>', 'format' => 'full_html'],
      'generateBodyPlain' => FALSE,
    ])->save();

    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->getConfigurableLanguageManager()
      ->getLanguageConfigOverride('fr', 'easy_email.easy_email_type.conreg_registration_test')
      ->set('subject', 'Bonjour')
      ->set('bodyHtml', ['value' => '<p>Bonjour</p>', 'format' => 'full_html'])
      ->save();
  }

  /**
   * A recipient language with a translation override gets the translated text.
   */
  public function testBuildUsesRecipientLanguageForTranslatedSubject(): void {
    $email = \Drupal::service(ConregEmailSender::class)
      ->build('conreg_registration_test', 'jane.doe@example.com', 1, [], 'fr');

    $this->assertSame('Bonjour', $email->getSubject());
    $htmlBody = $email->getHtmlBody();
    $this->assertSame('<p>Bonjour</p>', $htmlBody['value']);
  }

  /**
   * A NULL langcode falls back to the site's default language.
   */
  public function testBuildFallsBackToDefaultLanguageWhenLangcodeNull(): void {
    $email = \Drupal::service(ConregEmailSender::class)
      ->build('conreg_registration_test', 'jane.doe@example.com', 1, [], NULL);

    $this->assertSame('Hello', $email->getSubject());
  }

  /**
   * A langcode that isn't a configured language also falls back to default.
   *
   * A member's stored `language` column could in principle hold a language
   * that has since been uninstalled - LanguageManager::getLanguage()
   * returns NULL for it, and build() must not pass that NULL straight to
   * setConfigOverrideLanguage(), which requires a LanguageInterface.
   */
  public function testBuildFallsBackToDefaultForUnconfiguredLangcode(): void {
    $email = \Drupal::service(ConregEmailSender::class)
      ->build('conreg_registration_test', 'jane.doe@example.com', 1, [], 'xx');

    $this->assertSame('Hello', $email->getSubject());
  }

  /**
   * Build() restores the config override language it found before running.
   *
   * SetConfigOverrideLanguage() is global state - build() must not leak a
   * per-recipient language override into whatever runs next in the same
   * request (e.g. the next recipient in a bulk send).
   */
  public function testConfigOverrideLanguageIsRestoredAfterBuild(): void {
    $before = \Drupal::languageManager()->getConfigOverrideLanguage()->getId();

    \Drupal::service(ConregEmailSender::class)
      ->build('conreg_registration_test', 'jane.doe@example.com', 1, [], 'fr');

    $after = \Drupal::languageManager()->getConfigOverrideLanguage()->getId();
    $this->assertSame($before, $after);
  }

  /**
   * Get the language manager as a configurable language manager.
   *
   * @return \Drupal\language\ConfigurableLanguageManagerInterface
   *   The configurable language block.
   */
  protected function getConfigurableLanguageManager(): ConfigurableLanguageManagerInterface {
    return \Drupal::languageManager();
  }

}
