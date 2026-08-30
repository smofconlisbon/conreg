<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Admin\EventConfig;
use Drupal\conreg\Service\StripeServiceInterface;
use Drupal\Core\Database\Database;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Event Configuration form's Stripe key handling.
 *
 * Covers the key_select fields, the live "Key status" message, and the
 * update hook that clears plaintext keys left from before the migration to
 * the Key module - all of it specific to how Stripe keys are stored and
 * verified, rather than the general "does this form build" coverage in
 * FormBuildTest.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class EventConfigPaymentKeysTest extends KernelTestBase {

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
  }

  /**
   * The public/private Stripe key fields are key_select elements.
   *
   * They must store Key module entity IDs, not plain text secrets.
   */
  public function testPaymentKeyFieldsAreKeySelects(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertSame('key_select', $form['conreg_payments']['public_key']['#type']);
    $this->assertSame('key_select', $form['conreg_payments']['private_key']['#type']);
  }

  /**
   * No key status is shown until both payment keys are configured.
   */
  public function testNoKeyStatusWhenKeysNotConfigured(): void {
    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertArrayNotHasKey('key_status', $form['conreg_payments']);
  }

  /**
   * A configured key pair shows a live status message from StripeService.
   *
   * StripeService itself is swapped for a stub so this exercises
   * EventConfig's wiring - the key IDs it reads from config and passes to
   * verifyKeys() - without making a real network call.
   */
  public function testShowsKeyStatus(): void {
    Key::create([
      'id' => 'stripe_public_test',
      'label' => 'Stripe public test key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'pk_test_abc'],
    ])->save();
    Key::create([
      'id' => 'stripe_secret_test',
      'label' => 'Stripe secret test key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'sk_test_xyz'],
    ])->save();

    $this->container
      ->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('payments.public_key', 'stripe_public_test')
      ->set('payments.private_key', 'stripe_secret_test')
      ->save();

    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->expects($this->once())
      ->method('verifyKeys')
      ->with('stripe_public_test', 'stripe_secret_test', 'Test')
      ->willReturn(['valid' => TRUE, 'message' => 'Valid - keys verified with Stripe.']);
    $this->container->set('conreg.stripe_service', $stripeService);

    $form = $this->container
      ->get('form_builder')
      ->getForm(EventConfig::class);

    $this->assertSame(
      'Valid - keys verified with Stripe.',
      (string) $form['conreg_payments']['key_status']['#markup']
    );
  }

  /**
   * Update 9006 clears plaintext Stripe keys left from before the migration.
   *
   * They can't be left sitting in config, where they could be accidentally
   * exported or leaked.
   */
  public function testPlaintextStripeKeysRemovedByUpdate9006(): void {
    $storage = $this->container->get('config.storage');
    $data = $storage->read('conreg.settings.1');
    $data['payments']['public_key'] = 'pk_live_leaked';
    $data['payments']['private_key'] = 'sk_live_leaked';
    $storage->write('conreg.settings.1', $data);
    $this->container->get('config.factory')->reset('conreg.settings.1');

    $this->container->get('module_handler')->loadInclude('conreg', 'install');
    conreg_update_9006();
    $this->container->get('config.factory')->reset('conreg.settings.1');
    $config = $this->container->get('config.factory')->get('conreg.settings.1');

    $this->assertSame('', $config->get('payments.public_key'));
    $this->assertSame('', $config->get('payments.private_key'));
  }

  /**
   * Update 9006 is a no-op when there are no plaintext keys to remove.
   */
  public function testUpdate9006IsNoOpWithoutPlaintextKeys(): void {
    $this->container->get('module_handler')->loadInclude('conreg', 'install');

    // No exception, and existing (already-empty) keys stay untouched.
    conreg_update_9006();
    $this->addToAssertionCount(1);

    $config = $this->container->get('config.factory')->get('conreg.settings.1');
    $this->assertSame('', $config->get('payments.public_key'));
    $this->assertSame('', $config->get('payments.private_key'));
  }

}
