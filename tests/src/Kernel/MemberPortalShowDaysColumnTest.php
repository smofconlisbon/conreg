<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\MemberPortal;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "Days" column in the Member Portal's member list.
 *
 * Covers the list-rendering half of issue #3596647: whether the "days"
 * column actually appears in the built form based on the
 * "member_portal.show_days_column" config, as opposed to
 * EventConfigMemberPortalTest, which covers only the admin settings form
 * that sets that config.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MemberPortalShowDaysColumnTest extends KernelTestBase {

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

    // Required for #type = datetime.
    $this->installConfig(['system', 'datetime']);

    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
      'conreg_payments',
      'conreg_payment_lines',
    ]);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();

    $this->container->get('current_user')->setAccount(new UserSession([
      'mail' => 'test@example.com',
    ]));
    $this->createTestMember([
      'lead_mid' => 1,
      'member_type' => 'A',
      'days' => 'W',
      'badge_type' => 'A',
      'is_paid' => 1,
    ]);
  }

  /**
   * Create a member with most required fields.
   */
  protected function createTestMember(array $overrides = []): int {
    $defaults = [
      'mid' => 1,
      'eid' => 1,
      'language' => 'en',
      'first_name' => 'Test',
      'last_name' => 'User',
      'email' => 'test@example.com',
      'join_date' => \Drupal::time()->getCurrentTime(),
      'update_date' => \Drupal::time()->getCurrentTime(),
    ];

    return (int) Database::getConnection()
      ->insert('conreg_members')
      ->fields($overrides + $defaults)
      ->execute();
  }

  /**
   * With no config saved, the "Days" column defaults to shown.
   */
  public function testShowDaysColumnDefaultToShown(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(MemberPortal::class);

    $this->assertArrayHasKey('days', $form['table']['#header']);
    $this->assertEquals('Weekend', (string) $form['table'][1]['days']['#markup']);
  }

  /**
   * With the config explicitly enabled, the "Days" column is shown.
   */
  public function testShowDaysColumnShownWhenExplicitlyEnabled(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member_portal.show_days_column', TRUE)
      ->save();

    $form = $this->container
      ->get('form_builder')
      ->getForm(MemberPortal::class);

    $this->assertArrayHasKey('days', $form['table']['#header']);
    $this->assertEquals('Weekend', (string) $form['table'][1]['days']['#markup']);
  }

  /**
   * With the config disabled, the "Days" column is hidden.
   */
  public function testShowDaysColumnHidesColumnWhenDisabled(): void {
    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member_portal.show_days_column', FALSE)
      ->save();

    $form = $this->container
      ->get('form_builder')
      ->getForm(MemberPortal::class);

    $this->assertArrayNotHasKey('days', $form['table']['#header']);
    $this->assertArrayNotHasKey('days', $form['table'][1]);
  }

}
