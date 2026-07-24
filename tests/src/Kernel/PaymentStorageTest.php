<?php

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Service\PaymentStorage;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the payment storage service.
 *
 * @group conreg
 */
#[RunTestsInSeparateProcesses]
class PaymentStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'conreg',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('conreg', [
      'conreg_payments',
      'conreg_payment_lines',
    ]);
  }

  /**
   * Tests payment and payment line CRUD operations.
   */
  public function testPaymentStorage(): void {
    $storage = $this->container->get(PaymentStorage::class);

    $this->assertInstanceOf(PaymentStorage::class, $storage);
    $this->assertSame($storage, $this->container->get('conreg.payment.storage'));

    $payment_id = $storage->insert([
      'random_key' => 12345,
      'created_date' => 100,
      'payment_amount' => 20,
    ]);
    $this->assertIsInt($payment_id);
    $this->assertTrue($storage->checkPaymentKey($payment_id, 12345));
    $this->assertFalse($storage->checkPaymentKey($payment_id, 54321));

    $payment = $storage->load(['payid' => $payment_id]);
    $this->assertSame(20.0, (float) $payment['payment_amount']);

    $this->assertSame(1, $storage->update([
      'payid' => $payment_id,
      'payment_method' => 'Test',
    ]));
    $this->assertSame('Test', $storage->load(['payid' => $payment_id])['payment_method']);
    $this->assertCount(1, $storage->loadAll(['payid' => $payment_id]));

    $line_id = $storage->insertLine([
      'payid' => $payment_id,
      'mid' => 1,
      'payment_type' => 'member',
      'line_desc' => 'Membership',
      'amount' => 20,
    ]);
    $this->assertIsInt($line_id);
    $this->assertSame('Membership', $storage->loadLine(['lineid' => $line_id])['line_desc']);

    $this->assertSame(1, $storage->updateLine([
      'lineid' => $line_id,
      'line_desc' => 'Updated membership',
    ]));
    $this->assertSame('Updated membership', $storage->loadAllLines(['payid' => $payment_id])[0]['line_desc']);

    $storage->deleteLine(['lineid' => $line_id]);
    $this->assertFalse($storage->loadLine(['lineid' => $line_id]));

    $storage->delete(['payid' => $payment_id]);
    $this->assertFalse($storage->load(['payid' => $payment_id]));
  }

}
