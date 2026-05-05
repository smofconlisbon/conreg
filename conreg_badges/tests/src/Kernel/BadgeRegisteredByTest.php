<?php

namespace Drupal\Tests\conreg_badges\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the "registered by" column in badge name export.
 *
 * @group conreg
 */
class BadgeRegisteredByTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'conreg',
    'conreg_badges',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'datetime']);
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
  }

  /**
   * Tests that adminMemberBadges returns lead member data.
   */
  public function testAdminMemberBadgesReturnsLeadMemberData(): void {
    $db = Database::getConnection();
    $now = \Drupal::time()->getCurrentTime();

    // Create lead member (mid=1).
    $db->insert('conreg_members')->fields([
      'eid' => 1,
      'language' => 'en',
      'first_name' => 'Lead',
      'last_name' => 'Member',
      'email' => 'lead@example.com',
      'badge_name' => 'LeadBadge',
      'badge_type' => 'A',
      'member_no' => 1,
      'member_type' => 'full',
      'is_paid' => 1,
      'is_approved' => 1,
      'lead_mid' => 1,
      'join_date' => $now,
      'update_date' => $now,
    ])->execute();

    // Create member registered by lead (mid=2, lead_mid=1).
    $db->insert('conreg_members')->fields([
      'eid' => 1,
      'language' => 'en',
      'first_name' => 'Group',
      'last_name' => 'Member',
      'email' => 'group@example.com',
      'badge_name' => 'GroupBadge',
      'badge_type' => 'B',
      'member_no' => 2,
      'member_type' => 'full',
      'is_paid' => 1,
      'is_approved' => 1,
      'lead_mid' => 1,
      'join_date' => $now,
      'update_date' => $now,
    ])->execute();

    $storage = $this->container->get('conreg.member.storage');
    $entries = $storage->adminMemberBadges(1);

    $this->assertCount(2, $entries);

    // Lead member — lead_mid equals own mid.
    $lead = $entries[0];
    $this->assertEquals(1, $lead['mid']);
    $this->assertEquals(1, $lead['lead_mid']);
    $this->assertEquals(1, $lead['lead_member_no']);
    $this->assertEquals('A', $lead['lead_badge_type']);

    // Group member — lead_mid points to lead member.
    $group = $entries[1];
    $this->assertEquals(2, $group['mid']);
    $this->assertEquals(1, $group['lead_mid']);
    $this->assertEquals(1, $group['lead_member_no']);
    $this->assertEquals('A', $group['lead_badge_type']);
  }

  /**
   * Tests that badge names form builds with registered by checkbox.
   */
  public function testBadgeNamesFormHasRegisteredByCheckbox(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm('\Drupal\conreg_badges\Form\BadgeNamesForm', 1);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('fields', $form);
    $this->assertArrayHasKey('showRegisteredBy', $form['fields']);
    $this->assertEquals('checkbox', $form['fields']['showRegisteredBy']['#type']);
  }

}
