<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Checkout;
use Drupal\conreg\Payment;
use Drupal\conreg\PaymentLine;
use Drupal\conreg\Service\PaymentStorage;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore pi_3Nxyz

/**
 * Tests that the checkout thank-you page resolves conreg tokens.
 *
 * Checkout::showThankYouPage() used to do its own tiny, unrelated
 * str_replace(['[reference]', '[event_name]'], ...) substitution; it now
 * goes through the real Token API instead, the same as ConregEmailer.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class CheckoutThankYouPageTest extends KernelTestBase {

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
   * Set up database tables and config for the checkout form.
   */
  protected function setUp(): void {
    parent::setUp();

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
    Database::getConnection()->insert('conreg_members')
      ->fields([
        'mid' => 1,
        'eid' => 1,
        'lead_mid' => 1,
        'language' => 'en',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane.doe@example.com',
        'payment_id' => 'pi_3Nxyz',
        'join_date' => \Drupal::time()->getCurrentTime(),
        'update_date' => \Drupal::time()->getCurrentTime(),
      ])
      ->execute();
  }

  /**
   * Event-name and member payment-id tokens resolve in the rendered page.
   */
  public function testThankYouPageResolvesTokens(): void {
    $checkout = $this->container->get('class_resolver')->getInstanceFromDefinition(Checkout::class);
    $this->container->get('config.factory')->getEditable('conreg.settings.1')
      ->set('thanks.thank_you_message', 'Thanks for joining [conreg:event-name]! Ref: [conreg:member:payment-id].')
      ->save();
    $immutableConfig = $this->container->get('config.factory')->get('conreg.settings.1');

    $paymentStorage = $this->container->get(PaymentStorage::class);
    $payment = new Payment($paymentStorage);
    $payment->paymentRef = 'pi_3Nxyz';
    $payment->add(new PaymentLine($paymentStorage, 1, 'Membership', 'Adult', 50.0));

    $form = $checkout->showThankYouPage([], 1, $immutableConfig, $payment);

    $this->assertSame('Thanks for joining Test event! Ref: pi_3Nxyz.', $form['message']['#text']);
  }

}
