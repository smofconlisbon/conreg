<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_simplenews\Kernel;

use Drupal\conreg_mailing_list\MailingListProviderPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simplenews\Entity\Newsletter;
use Drupal\simplenews\Entity\Subscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the simplenews MailingListProvider plugin against the real API.
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class SimplenewsProviderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'user',
    'field',
    'options',
    'views',
    'simplenews',
    'key',
    'conreg',
    'conreg_mailing_list',
    'conreg_simplenews',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('simplenews_subscriber');
    $this->installEntitySchema('simplenews_subscriber_history');
  }

  /**
   * The getLists() method returns every newsletter as id => label.
   */
  public function testGetListsReturnsNewsletters(): void {
    Newsletter::create(['id' => 'first', 'name' => 'First Newsletter'])->save();
    Newsletter::create(['id' => 'second', 'name' => 'Second Newsletter'])->save();

    $lists = $this->createProvider()->getLists();

    $this->assertSame([
      'first' => 'First Newsletter',
      'second' => 'Second Newsletter',
    ], $lists);
  }

  /**
   * The getLists() method returns an empty array when there are none.
   */
  public function testGetListsReturnsEmptyArrayWhenNoNewsletters(): void {
    $this->assertSame([], $this->createProvider()->getLists());
  }

  /**
   * The subscribe() method creates a subscriber for the newsletter.
   */
  public function testSubscribeCreatesSubscriber(): void {
    Newsletter::create(['id' => 'first', 'name' => 'First Newsletter'])->save();

    $this->createProvider()->subscribe('new@example.com', 'first', []);

    $subscriber = Subscriber::loadByMail('new@example.com');
    $this->assertNotNull($subscriber);
    $this->assertTrue($subscriber->isSubscribed('first'));
  }

  /**
   * Subscribing the same email twice doesn't create duplicate subscribers.
   */
  public function testSubscribeIsIdempotent(): void {
    Newsletter::create(['id' => 'first', 'name' => 'First Newsletter'])->save();

    $provider = $this->createProvider();
    $provider->subscribe('repeat@example.com', 'first', []);
    $provider->subscribe('repeat@example.com', 'first', []);

    $count = $this->container->get('entity_type.manager')
      ->getStorage('simplenews_subscriber')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('mail', 'repeat@example.com')
      ->count()
      ->execute();

    $this->assertSame(1, $count);
    $this->assertTrue(Subscriber::loadByMail('repeat@example.com')->isSubscribed('first'));
  }

  /**
   * Builds the simplenews provider plugin instance under test.
   */
  protected function createProvider() {
    /** @var \Drupal\conreg_mailing_list\MailingListProviderPluginManager $manager */
    $manager = $this->container->get(MailingListProviderPluginManager::class);
    return $manager->createInstance('simplenews');
  }

}
