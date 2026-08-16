<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailerlite\Kernel;

use Drupal\conreg_mailerlite\Form\ConfigMailerliteForm;
use Drupal\Core\Form\FormState;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ConfigMailerliteForm's live key-validity status display.
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConfigMailerliteFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'conreg',
    'conreg_mailing_list',
    'conreg_mailerlite',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * No status is shown when no key has been selected yet.
   */
  public function testNoStatusWhenNoKeyConfigured(): void {
    $form = $this->buildForm();

    $this->assertArrayNotHasKey('api_key_status', $form);
  }

  /**
   * A working key shows a valid status with the real list count.
   */
  public function testValidKeyShowsListCount(): void {
    $this->createKey('mailerlite_test_key', 'a-real-secret');
    $this->setConfiguredKeyId('mailerlite_test_key');
    $data = [
      'data' => [
        ['id' => '1', 'name' => 'List One'],
        ['id' => '2', 'name' => 'List Two'],
      ],
    ];
    $this->setHttpClient([new Response(200, [], json_encode($data))]);

    $form = $this->buildForm();

    $this->assertSame('Valid - 2 mailing lists found.', (string) $form['api_key_status']['#markup']);
  }

  /**
   * A rejected key shows an invalid status.
   */
  public function testInvalidKeyShowsInvalidMessage(): void {
    $this->createKey('mailerlite_test_key', 'a-bad-secret');
    $this->setConfiguredKeyId('mailerlite_test_key');
    $this->setHttpClient([
      new Response(401, [], ''),
    ]);

    $form = $this->buildForm();

    $this->assertSame('Invalid key.', (string) $form['api_key_status']['#markup']);
  }

  /**
   * Builds the settings form via the real form builder.
   */
  protected function buildForm(): array {
    $formObject = ConfigMailerliteForm::create($this->container);
    $formState = new FormState();
    return $this->container->get('form_builder')->buildForm($formObject, $formState);
  }

  /**
   * Creates a Key entity backed by the "config" provider.
   */
  protected function createKey(string $id, string $value): void {
    Key::create([
      'id' => $id,
      'label' => $id,
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => $value],
    ])->save();
  }

  /**
   * Points conreg_mailerlite.settings:api_key at the given Key ID.
   */
  protected function setConfiguredKeyId(string $keyId): void {
    $this->container->get('config.factory')
      ->getEditable('conreg_mailerlite.settings')
      ->set('api_key', $keyId)
      ->save();
  }

  /**
   * Swaps the http_client service for one backed by canned responses.
   */
  protected function setHttpClient(array $responses): void {
    $stack = HandlerStack::create(new MockHandler($responses));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

}
