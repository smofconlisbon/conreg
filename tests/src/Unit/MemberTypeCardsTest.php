<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Unit;

use Drupal\conreg\Element\MemberTypeCards;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests MemberTypeCards::processMemberTypeCards() card-expansion logic.
 */
#[CoversClass(MemberTypeCards::class)]
#[Group('conreg')]
class MemberTypeCardsTest extends UnitTestCase {

  /**
   * Builds a base element array, merging in overrides.
   */
  protected function createElement(array $overrides = []): array {
    return $overrides + [
      '#options' => ['a' => 'Type A', 'b' => 'Type B'],
      '#member_types' => [],
      '#disabled_options' => [],
      '#default_value' => NULL,
      '#currency_symbol' => '$',
      '#parents' => ['type'],
    ];
  }

  /**
   * Runs the process callback and returns the resulting element.
   */
  protected function process(array $element): array {
    $formState = new FormState();
    $completeForm = [];
    return MemberTypeCards::processMemberTypeCards($element, $formState, $completeForm);
  }

  /**
   * A card's title comes from the member type's name when data is present.
   */
  public function testTitleUsesMemberTypeName(): void {
    $element = $this->createElement([
      '#member_types' => ['a' => (object) ['name' => 'Adult']],
    ]);

    $result = $this->process($element);

    $this->assertSame('Adult', $result['a']['#title']);
  }

  /**
   * A card falls back to the raw option label with no member-type data.
   */
  public function testTitleFallsBackToOptionLabelWhenNoMemberTypeData(): void {
    $result = $this->process($this->createElement());

    $this->assertSame('Type A', $result['a']['#title']);
  }

  /**
   * The description render array uses the type's description and format.
   */
  public function testCardDescriptionUsesMemberTypeDescriptionAndFormat(): void {
    $element = $this->createElement([
      '#member_types' => [
        'a' => (object) ['description' => 'All ages welcome', 'descriptionFormat' => 'full_html'],
      ],
    ]);

    $result = $this->process($element);

    $this->assertSame('processed_text', $result['a']['#card_description']['#type']);
    $this->assertSame('All ages welcome', $result['a']['#card_description']['#text']);
    $this->assertSame('full_html', $result['a']['#card_description']['#format']);
  }

  /**
   * The description falls back to the option label and basic_html format.
   */
  public function testCardDescriptionFallsBackWhenNoMemberTypeData(): void {
    $result = $this->process($this->createElement());

    $this->assertSame('Type A', $result['a']['#card_description']['#text']);
    $this->assertSame('basic_html', $result['a']['#card_description']['#format']);
  }

  /**
   * Price and currency symbol pass through from the type data / element.
   */
  public function testCardPriceAndCurrencySymbolPassThrough(): void {
    $element = $this->createElement([
      '#member_types' => ['a' => (object) ['price' => '25.00']],
      '#currency_symbol' => '£',
    ]);

    $result = $this->process($element);

    $this->assertSame('25.00', $result['a']['#card_price']);
    $this->assertSame('£', $result['a']['#currency_symbol']);
  }

  /**
   * A key with no member-type data gets an empty card price.
   */
  public function testCardPriceEmptyWhenNoMemberTypeData(): void {
    $result = $this->process($this->createElement());

    $this->assertSame('', $result['a']['#card_price']);
  }

  /**
   * An option in #disabled_options is marked disabled on both flags.
   */
  public function testDisabledOptionMarksBothDisabledFlags(): void {
    $element = $this->createElement(['#disabled_options' => ['a' => TRUE]]);

    $result = $this->process($element);

    $this->assertTrue($result['a']['#disabled']);
    $this->assertTrue($result['a']['#card_disabled']);
    $this->assertFalse($result['b']['#disabled']);
    $this->assertFalse($result['b']['#card_disabled']);
  }

  /**
   * Every radio is taken out of the tab order; focus moves via the card.
   */
  public function testEveryRadioHasTabindexMinusOne(): void {
    $result = $this->process($this->createElement());

    $this->assertSame('-1', $result['a']['#attributes']['tabindex']);
    $this->assertSame('-1', $result['b']['#attributes']['tabindex']);
  }

  /**
   * The aria-describedby attribute is only set when there's a description.
   */
  public function testAriaDescribedbySetOnlyWhenDescriptionPresent(): void {
    $element = $this->createElement([
      '#member_types' => ['a' => (object) ['description' => 'Has a description']],
    ]);

    $result = $this->process($element);

    $this->assertArrayHasKey('aria-describedby', $result['a']['#attributes']);
    $this->assertArrayNotHasKey('aria-describedby', $result['b']['#attributes']);
  }

  /**
   * A container-level aria-describedby is not inherited by child radios.
   */
  public function testContainerAriaDescribedbyIsNotInherited(): void {
    $element = $this->createElement([
      '#attributes' => ['aria-describedby' => 'some-other-element'],
    ]);

    $result = $this->process($element);

    $this->assertArrayNotHasKey('aria-describedby', $result['a']['#attributes']);
    $this->assertArrayNotHasKey('aria-describedby', $result['b']['#attributes']);
  }

  /**
   * With no default value, the first enabled option becomes tabbable.
   */
  public function testTabbableKeyDefaultsToFirstOption(): void {
    $result = $this->process($this->createElement());

    $this->assertSame('a', $result['#tabbable_key']);
  }

  /**
   * With no default value, a disabled first option is skipped.
   */
  public function testTabbableKeySkipsDisabledFirstOption(): void {
    $element = $this->createElement(['#disabled_options' => ['a' => TRUE]]);

    $result = $this->process($element);

    $this->assertSame('b', $result['#tabbable_key']);
  }

  /**
   * A default value matching a later option takes priority over the first.
   */
  public function testTabbableKeyPrefersDefaultValueOverFirstOption(): void {
    $element = $this->createElement(['#default_value' => 'b']);

    $result = $this->process($element);

    $this->assertSame('b', $result['#tabbable_key']);
  }

  /**
   * An empty #options array leaves the element unprocessed.
   */
  public function testEmptyOptionsSkipsProcessing(): void {
    $result = $this->process($this->createElement(['#options' => []]));

    $this->assertArrayNotHasKey('a', $result);
    $this->assertArrayNotHasKey('#tabbable_key', $result);
  }

}
