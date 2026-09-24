<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit\Service;

use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\MemberStorage;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests ConregOptions's config-parsing logic in isolation.
 *
 * Covers the methods whose logic is pure config parsing (no extra Drupal
 * machinery beyond config/translation). memberClasses() (cache-tag/
 * invalidation timing) and memberCountries() (pulls in CountryManager +
 * module handler + translation for country names) are left for follow-up
 * coverage; memberTypes()'s complex shape is already exercised by
 * RegistrationMemberTypeCardsTest.php at the Kernel level.
 */
#[Group('conreg')]
class ConregOptionsTest extends UnitTestCase {

  /**
   * Builds a ConregOptions service with the given event 1 config values.
   *
   * The cache backend always reports a miss so the real parsing logic
   * runs on every call, and translation is wired up via the core stub so
   * paymentMethod()/yesNo() can call t() without a full container.
   */
  protected function buildService(array $configValues): ConregOptions {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn(new Language(['id' => 'en']));

    return new ConregOptions(
      $cache,
      $this->getConfigFactoryStub(['conreg.settings.1' => $configValues]),
      $languageManager,
      $this->createMock(MemberStorage::class),
      $this->createMock(ModuleHandlerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * The days() method parses "code|name" lines into an associative array.
   */
  public function testDaysParsesCodePipeNameLines(): void {
    $service = $this->buildService(['days' => ['A|Thursday', 'B|Friday']]);

    $this->assertSame(['A' => 'Thursday', 'B' => 'Friday'], $service->days(1));
  }

  /**
   * The badgeTypes() method parses "code|label" lines the same way.
   */
  public function testBadgeTypesParsesCodePipeLabelLines(): void {
    $service = $this->buildService(['badge_types' => ['S|Supporting', 'A|Attending']]);

    $this->assertSame(['S' => 'Supporting', 'A' => 'Attending'], $service->badgeTypes(1));
  }

  /**
   * The display() method returns the configured options keyed by code.
   */
  public function testDisplayReturnsConfiguredOptionsKeyedByCode(): void {
    $service = $this->buildService(['display_options' => ['options' => ['F|Full Name', 'N|Nickname']]]);

    $this->assertSame(['F' => 'Full Name', 'N' => 'Nickname'], $service->display(1));
  }

  /**
   * The displayDefault() method falls back to 'F' when no default is set.
   */
  public function testDisplayDefaultFallsBackToDefaultCodeWhenUnset(): void {
    $service = $this->buildService([]);

    $this->assertSame('F', $service->displayDefault(1));
  }

  /**
   * The displayDefault() method returns the configured default when set.
   */
  public function testDisplayDefaultReturnsConfiguredValue(): void {
    $service = $this->buildService(['display_options' => ['default' => 'N']]);

    $this->assertSame('N', $service->displayDefault(1));
  }

  /**
   * The communicationMethod() method drops non-public methods when told to.
   *
   * A method is public when its third field is '1' or blank - anything
   * else (e.g. '0') marks it admin-only and must be dropped when
   * $publicOnly is true, but kept when it's false.
   */
  public function testCommunicationMethodFiltersPrivateOptionsWhenPublicOnly(): void {
    $service = $this->buildService([
      'communications_method' => ['options' => ['E|Email|1', 'P|Phone|0', 'M|Mail|']],
    ]);

    $this->assertSame(
      ['E' => 'Email', 'M' => 'Mail'],
      $service->communicationMethod(1, TRUE),
    );
    $this->assertSame(
      ['E' => 'Email', 'P' => 'Phone', 'M' => 'Mail'],
      $service->communicationMethod(1, FALSE),
    );
  }

  /**
   * The memberUpgrades() method parses 8 pipe-separated fields per line.
   *
   * Each field is trimmed, so stray whitespace or a leftover carriage
   * return on an individual array element (e.g. from a config value
   * written directly rather than through the admin form's
   * TextareaLines::toArray() normalization) doesn't leak into the last
   * field, such as the price.
   */
  public function testMemberUpgradesParsesAndTrimsPipeSeparatedFields(): void {
    $service = $this->buildService([
      'payments' => ['show_remaining' => FALSE],
      'days' => ['W|Weekend'],
      'member' => [
        'types' => [
          'S' => [
            'name' => 'Supporting',
            'description' => 'Supporting membership',
            'price' => '20',
            'badgeType' => 'S',
            'memberClass' => 'Default',
            'allowFirst' => TRUE,
            'active' => TRUE,
            'allowDuplicates' => FALSE,
            'number_allowed' => 0,
          ],
        ],
      ],
      'member_upgrades' => ["101|S|W|A|W|Attending|Attending upgrade|45\r"],
    ]);

    $upgrades = $service->memberUpgrades(1);

    $this->assertSame('Attending upgrade', $upgrades->upgrades[101]->desc);
    $this->assertSame('45', $upgrades->upgrades[101]->price);
    $this->assertSame([0 => 'Supporting', 101 => 'Attending upgrade'], $upgrades->options['S']['W']);
  }

  /**
   * The memberAddons() method returns options and prices keyed by description.
   */
  public function testMemberAddonsReturnsOptionsAndPricesKeyedByDescription(): void {
    $service = $this->buildService(['add_ons' => ['options' => "T-Shirt|10\nMug|5"]]);

    [$options, $prices] = $service->memberAddons(1);

    $this->assertSame(['T-Shirt' => 'T-Shirt', 'Mug' => 'Mug'], $options);
    $this->assertSame(['T-Shirt' => '10', 'Mug' => '5'], $prices);
  }

  /**
   * The paymentMethod() method returns the fixed, translated payment list.
   */
  public function testPaymentMethodReturnsFixedTranslatedList(): void {
    $service = $this->buildService([]);

    $methods = $service->paymentMethod();

    $this->assertSame(
      ['Stripe', 'Bank Transfer', 'Cash', 'Cheque', 'Credit Card', 'Free', 'Groats', 'PayPal'],
      array_keys($methods),
    );
    $this->assertSame('Stripe', (string) $methods['Stripe']);
  }

  /**
   * The yesNo() method returns 0 => No, 1 => Yes.
   */
  public function testYesNoReturnsZeroFalseOneTrue(): void {
    $service = $this->buildService([]);

    $yesNo = $service->yesNo();

    $this->assertSame('No', (string) $yesNo[0]);
    $this->assertSame('Yes', (string) $yesNo[1]);
  }

  /**
   * The memberTypes() method builds type objects and public/first option lists.
   *
   * Uses payments.show_remaining: false so this doesn't need to mock the
   * injected MemberStorage call or the t()-using "remaining" branch -
   * those are exercised at the Kernel level instead.
   */
  public function testMemberTypesBuildsTypeObjectsFromConfig(): void {
    $service = $this->buildService([
      'payments' => ['show_remaining' => FALSE],
      'days' => ['1|Day One'],
      'member' => [
        'types' => [
          'A' => [
            'name' => 'Attending',
            'description' => 'Full weekend attendance',
            'price' => '50',
            'badgeType' => 'A',
            'memberClass' => 'Default',
            'allowFirst' => TRUE,
            'active' => TRUE,
            'allowDuplicates' => FALSE,
            'number_allowed' => 0,
          ],
        ],
      ],
    ]);

    $types = $service->memberTypes(1);

    $this->assertSame('Attending', $types->types['A']->name);
    $this->assertSame(['A' => 'Attending'], $types->firstOptions);
    $this->assertSame(['A' => 'Attending'], $types->publicOptions);
    $this->assertSame(['A' => 'Attending'], $types->privateOptions);
  }

}
