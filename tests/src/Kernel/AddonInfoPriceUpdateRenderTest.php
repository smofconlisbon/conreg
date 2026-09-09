<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\MemberEdit;
use Drupal\conreg\Form\Registration;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests issue #3596645: render NULL for an add-on with no "info" field.
 *
 * `updateMemberPriceCallback()` (on both Registration and MemberEdit) checks
 * whether an add-on's "extra" form container is empty before rendering its
 * "info" sub-element - but Addons::getAddon() always populates "extra" with
 * at least `#prefix`/`#suffix`, regardless of whether "info" was actually
 * added to it. An add-on with no configured info label never gets an "info"
 * element, so the check always passes and `$this->renderer->render()` is
 * called with NULL.
 *
 * Registration's callback always processes both the per-member add-on
 * section and the global add-on section in one call (it doesn't branch on
 * which one triggered the request), so a single call exercises both of the
 * two occurrences in that class.
 *
 * Unlike the similar bugs in tests/src/Unit/Form/Admin/MemberClassesTest.php
 * and tests/src/Unit/UpgradeManagerTest.php, this doesn't fail via
 * phpunit.xml.dist's failOnWarning="true": Drupal core's Renderer flags this
 * particular deprecation with a bare trigger_error() (no explicit level),
 * which PHP treats as E_USER_NOTICE, not E_WARNING - and failOnNotice isn't
 * set. So each test installs its own error handler around the callback call
 * to catch the notice directly, regardless of that project-wide setting.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class AddonInfoPriceUpdateRenderTest extends KernelTestBase {

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

    // Configure a per-member add-on and a global add-on, neither with an
    // "extra info" field configured - so Addons::getAddon() never adds an
    // "info" element for either of them.
    $this->container->get('config.factory')->getEditable('conreg.settings.1')
      ->set('add-ons.member_addon.addon', [
        'label' => 'Member add-on',
        'active' => 1,
        'global' => 0,
        'free' => 0,
        'options' => "Opt1|Description one|0\nOpt2|Description two|5",
        'description' => '',
      ])
      ->set('add-ons.global_addon.addon', [
        'label' => 'Global add-on',
        'active' => 1,
        'global' => 1,
        'free' => 0,
        'options' => "Opt1|Description one|0\nOpt2|Description two|5",
        'description' => '',
      ])
      ->save();
  }

  /**
   * Runs $callback with a local error handler capturing any PHP notices.
   *
   * @return array
   *   The messages of any E_NOTICE/E_USER_NOTICE raised while $callback ran.
   */
  protected function captureNotices(callable $callback): array {
    $messages = [];
    set_error_handler(function (int $errno, string $errstr) use (&$messages): bool {
      $messages[] = $errstr;
      return TRUE;
    }, E_NOTICE | E_USER_NOTICE);
    try {
      $callback();
    }
    finally {
      restore_error_handler();
    }
    return $messages;
  }

  /**
   * Updating an add-on with no info field must not warn.
   *
   * Covers both occurrences in Registration::updateMemberPriceCallback():
   * the per-member add-on section and the global add-on section, since one
   * call to the callback always processes both.
   */
  public function testRegistrationAddonUpdateDoesNotWarn(): void {
    $formState = new FormState();
    $formState->setValues(['global' => ['member_quantity' => 1]]);
    $registration = $this->container->get('class_resolver')->getInstanceFromDefinition(Registration::class);
    $form = $registration->buildForm([], $formState, 1);

    $this->assertArrayNotHasKey('info', $form['members']['member1']['add_on']['member_addon']['extra']);
    $this->assertArrayNotHasKey('info', $form['payment']['global_add_on']['global_addon']['extra']);

    // A real AJAX request always has a triggering element; simulate the
    // per-member add-on's own option dropdown changing.
    $formState->setTriggeringElement(['#name' => 'members[member1][add_on][member_addon][option]']);
    $response = NULL;
    $notices = $this->captureNotices(function () use ($registration, $form, $formState, &$response) {
      $response = $registration->updateMemberPriceCallback($form, $formState);
    });

    $this->assertSame([], $notices);
    $this->assertInstanceOf(AjaxResponse::class, $response);
  }

  /**
   * Updating an add-on with no info field on member edit must not warn.
   */
  public function testMemberEditAddonUpdateDoesNotWarn(): void {
    // Editing requires the editor's own member record to match, by email,
    // either the target member or their lead - so the current user and the
    // member are given the same email, with the member as their own lead.
    $this->container->get('current_user')->setAccount(new UserSession(['mail' => 'owner@example.com']));

    Database::getConnection()->insert('conreg_members')
      ->fields([
        'eid' => 1,
        'member_no' => 1,
        'language' => 'en',
        'is_approved' => 1,
        'is_paid' => 1,
        'email' => 'owner@example.com',
        'first_name' => 'Test',
        'last_name' => 'User',
        'badge_name' => 'Test User',
        'member_type' => 'A',
        'days' => NULL,
        'badge_type' => 'A',
        'display' => 'F',
        'country' => 'IE',
        'is_deleted' => 0,
      ])
      ->execute();
    $mid = (int) Database::getConnection()->select('conreg_members', 'm')
      ->fields('m', ['mid'])
      ->execute()
      ->fetchField();
    Database::getConnection()->update('conreg_members')
      ->fields(['lead_mid' => $mid])
      ->condition('mid', $mid)
      ->execute();

    $formState = new FormState();
    $formState->setTriggeringElement(['#name' => 'member[add_on][member_addon][option]']);
    $memberEdit = $this->container->get('class_resolver')->getInstanceFromDefinition(MemberEdit::class);
    $form = $memberEdit->buildForm([], $formState, 1, $mid);

    $this->assertArrayNotHasKey('info', $form['member']['add_on']['member_addon']['extra']);

    $response = NULL;
    $notices = $this->captureNotices(function () use ($memberEdit, $form, $formState, &$response) {
      $response = $memberEdit->updateMemberPriceCallback($form, $formState);
    });

    $this->assertSame([], $notices);
    $this->assertInstanceOf(AjaxResponse::class, $response);
  }

}
