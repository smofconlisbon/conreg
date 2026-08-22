<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailing_list\Kernel;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\conreg_mailing_list\MailingListProviderInterface;
use Drupal\conreg_mailing_list\MailingListProviderPluginManager;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore testprovider

/**
 * Tests discovery and instantiation of mailing_list_provider plugins.
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MailingListProviderPluginManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'conreg',
    'conreg_mailing_list',
  ];

  /**
   * The plugin manager under test.
   */
  protected MailingListProviderPluginManager $providerManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->providerManager = $this->container->get(MailingListProviderPluginManager::class);
  }

  /**
   * The bundled TestProvider plugin is discovered with its declared label.
   */
  public function testDiscoversTestProviderPlugin(): void {
    $definitions = $this->providerManager->getDefinitions();

    $this->assertArrayHasKey('testprovider', $definitions);
    $this->assertSame('Test Provider', (string) $definitions['testprovider']['label']);
  }

  /**
   * Creating an instance returns a working MailingListProviderInterface.
   */
  public function testCreateInstanceReturnsProvider(): void {
    $provider = $this->providerManager->createInstance('testprovider');

    $this->assertInstanceOf(MailingListProviderInterface::class, $provider);
    $this->assertSame('Test Provider', $provider->label());
    $this->assertSame([1 => 'Test list'], array_map('strval', $provider->getLists()));
  }

  /**
   * Requesting an unknown plugin ID throws.
   */
  public function testCreateInstanceUnknownPluginThrows(): void {
    $this->expectException(PluginNotFoundException::class);
    $this->providerManager->createInstance('does_not_exist');
  }

}
