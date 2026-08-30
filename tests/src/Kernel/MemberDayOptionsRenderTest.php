<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Element\MemberDayOptions;
use Drupal\conreg\Hook\ConregThemeHooks;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore mixedcase

/**
 * Tests the rendered HTML of the member_day_options theme hook.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MemberDayOptionsRenderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'conreg',
    'user',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * Builds and processes a member_day_options element, ready to theme.
   */
  protected function buildProcessedElement(array $overrides = []): array {
    $element = $overrides + [
      '#options' => ['x' => 'Weekend only'],
      '#day_data' => [],
      '#default_value' => NULL,
      '#currency_symbol' => '$',
      '#parents' => ['days'],
      '#value' => [],
      '#attributes' => [],
    ];

    $formState = new FormState();
    $completeForm = [];

    return MemberDayOptions::processMemberDayOptions($element, $formState, $completeForm);
  }

  /**
   * Builds, processes, and renders a single-option member_day_options element.
   */
  protected function renderOptions(array $overrides = []): string {
    $element = $this->buildProcessedElement($overrides);
    $element['#theme_wrappers'] = ['member_day_options'];

    return (string) $this->container->get('renderer')->renderRoot($element);
  }

  /**
   * A price of 0 renders "Free".
   */
  public function testZeroPriceRendersFree(): void {
    $html = $this->renderOptions([
      '#day_data' => ['x' => (object) ['name' => 'Weekend', 'description' => 'Weekend only', 'price' => 0]],
    ]);

    $this->assertStringContainsString('>Free<', $html);
  }

  /**
   * A nonzero price renders before the description.
   *
   * The currency symbol and price appear first, then the description.
   */
  public function testNonzeroPriceRendersCurrencySymbolAndPriceBeforeDescription(): void {
    $html = $this->renderOptions([
      '#day_data' => ['x' => (object) ['name' => 'Saturday', 'description' => 'Saturday', 'price' => '25']],
      '#currency_symbol' => '€',
    ]);

    $this->assertStringContainsString('>€25<', $html);
    $this->assertStringNotContainsString('>Free<', $html);
    $priceOffset = strpos($html, '€25');
    $descriptionOffset = strpos($html, 'Saturday');
    $this->assertNotFalse($priceOffset);
    $this->assertNotFalse($descriptionOffset);
    $this->assertLessThan($descriptionOffset, $priceOffset, 'Price renders before the description.');
  }

  /**
   * A day with no price data renders only the description, as before.
   */
  public function testNoDayDataRendersDescriptionOnlyWithoutPrice(): void {
    $html = $this->renderOptions();

    $this->assertStringContainsString('Weekend only', $html);
    $this->assertStringNotContainsString('member-day-option__price', $html);
  }

  /**
   * The group's #description renders below the day checkboxes.
   *
   * With an id matching FormBuilder's own aria-describedby="{id}--description"
   * (added to the group's attributes independently of the theme hook), so
   * the reference resolves to a real element instead of nothing.
   */
  public function testGroupDescriptionRendersBelowOptionsWithMatchingId(): void {
    $html = $this->renderOptions([
      '#id' => 'edit-test-days',
      '#description' => 'Pick as many days as you like.',
    ]);

    $this->assertStringContainsString('form-item__description', $html);
    $this->assertStringContainsString('id="edit-test-days--description"', $html);
    $optionsOffset = strpos($html, 'member-day-options__options');
    $descriptionOffset = strpos($html, 'Pick as many days as you like.');
    $this->assertNotFalse($optionsOffset);
    $this->assertNotFalse($descriptionOffset);
    $this->assertLessThan($descriptionOffset, $optionsOffset, 'Description renders after (below) the day checkboxes.');
  }

  /**
   * The preprocess step builds per-day theme suggestions.
   */
  public function testPreprocessBuildsThemeSuggestions(): void {
    $element = $this->buildProcessedElement([
      '#options' => ['x' => 'X', 'MixedCase!' => 'Mixed'],
    ]);

    $variables = ['element' => $element];
    $this->container->get(ConregThemeHooks::class)->preprocessMemberDayOptions($variables);

    $this->assertSame(['member_day_option__x', 'member_day_option'], $variables['options']['x']['#theme']);
    $this->assertSame(['member_day_option__mixedcase_', 'member_day_option'], $variables['options']['MixedCase!']['#theme']);
  }

  /**
   * A member-day-option--{suggestion}.html.twig override is picked up.
   *
   * Proves the file-based per-day template override actually works end to
   * end, matching the equivalent member-type-card coverage in
   * MemberTypeCardsRenderTest::testPerTypeTemplateOverrideIsPickedUp().
   */
  public function testPerDayTemplateOverrideIsPickedUp(): void {
    \Drupal::service('theme_installer')->install(['conreg_test_theme']);
    $this->config('system.theme')->set('default', 'conreg_test_theme')->save();

    $html = $this->renderOptions([
      '#options' => ['Fr' => 'Friday only'],
      '#day_data' => ['Fr' => (object) ['name' => 'Friday', 'description' => 'Friday only', 'price' => '12.00']],
    ]);

    $this->assertStringContainsString('conreg-test-day-option-override', $html);
    $this->assertStringNotContainsString('member-day-option__price', $html);
  }

}
