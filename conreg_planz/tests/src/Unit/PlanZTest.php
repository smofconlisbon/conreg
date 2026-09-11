<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_planz\Unit;

use Drupal\conreg_planz\BadgeIdSource;
use Drupal\conreg_planz\PlanZ;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests PlanZ::__construct()'s handling of its optional $config parameter.
 *
 * Regression coverage for #3596623: $config is typed `ImmutableConfig|NULL
 * $config = NULL`, but every property assignment calls $config->get() directly
 * with no null check first, so every one of the per-field `?:`/`??` defaults on
 * those lines is unreachable when the parameter is omitted - the call threw a
 * fatal error on `get()` before any default can be supplied.
 */
#[CoversClass(PlanZ::class)]
#[Group('conreg')]
class PlanZTest extends UnitTestCase {

  /**
   * Builds a mocked config returning NULL for every key.
   *
   * Mirrors what a config object with nothing set would return.
   */
  protected function emptyConfig(): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    return $config;
  }

  /**
   * Asserts a PlanZ instance holds every documented default value.
   */
  protected function assertHasDefaultValues(PlanZ $planz): void {
    $this->assertSame('default', $planz->target);
    $this->assertSame(BadgeIdSource::MemberNumber, $planz->badgeIdSource);
    $this->assertSame('', $planz->prefix);
    $this->assertSame(4, $planz->digits);
    $this->assertFalse($planz->generatePassword);
    $this->assertSame([], $planz->roles);
    $this->assertFalse($planz->interestedDefault);
    $this->assertSame('', $planz->planZUrl);
    $this->assertSame([], $planz->optionFields);
    $this->assertFalse($planz->autoEnabled);
    $this->assertFalse($planz->autoWhenConfirmed);
    $this->assertSame('', $planz->emailEasyEmailType);
  }

  /**
   * Omitting $config entirely must fall back to the per-field defaults.
   *
   * Currently throws a fatal error with "Call to a member function get() on
   * null" instead, because $config is dereferenced before any null fallback
   * runs.
   */
  public function testConstructorAcceptsMissingConfig(): void {
    $planz = new PlanZ();

    $this->assertHasDefaultValues($planz);
  }

  /**
   * A config that returns NULL for every key must behave identically.
   *
   * Confirms that once fixed, the missing-$config case is treated the same
   * as "a config with nothing set" rather than some other special case.
   */
  public function testEmptyConfigProducesDefaultValues(): void {
    $planz = new PlanZ($this->emptyConfig());

    $this->assertHasDefaultValues($planz);
  }

  /**
   * Non-regression: values from a populated config are still read correctly.
   */
  public function testConstructorUsesSuppliedConfigValues(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['target', 'planz_db'],
      ['badge_id_source', 'mid'],
      ['prefix', 'A'],
      ['digits', 5],
      ['generate_password', TRUE],
      ['roles', ['authenticated']],
      ['interested_default', TRUE],
      ['url', 'https://planz.example.com'],
      ['option_fields', ['field_interests']],
      ['auto.enabled', TRUE],
      ['auto.when_confirmed', TRUE],
      ['email.easy_email_type', 'planz_invite'],
    ]);

    $planz = new PlanZ($config);

    $this->assertSame('planz_db', $planz->target);
    $this->assertSame(BadgeIdSource::MemberID, $planz->badgeIdSource);
    $this->assertSame('A', $planz->prefix);
    $this->assertSame(5, $planz->digits);
    $this->assertTrue($planz->generatePassword);
    $this->assertSame(['authenticated'], $planz->roles);
    $this->assertTrue($planz->interestedDefault);
    $this->assertSame('https://planz.example.com', $planz->planZUrl);
    $this->assertSame(['field_interests'], $planz->optionFields);
    $this->assertTrue($planz->autoEnabled);
    $this->assertTrue($planz->autoWhenConfirmed);
    $this->assertSame('planz_invite', $planz->emailEasyEmailType);
  }

}
