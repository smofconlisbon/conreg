<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Element\MemberTypeCards;
use Drupal\conreg\Hook\ConregThemeHooks;
use Drupal\Core\Form\FormState;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore mixedcase

/**
 * Tests the rendered HTML of the member_type_cards theme hook.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class MemberTypeCardsRenderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'filter', 'conreg'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter']);
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
    ])->save();
  }

  /**
   * Builds and processes a member_type_cards element, ready to theme.
   */
  protected function buildProcessedElement(array $overrides = []): array {
    $element = $overrides + [
      '#options' => ['x' => 'X'],
      '#member_types' => [],
      '#disabled_options' => [],
      '#default_value' => NULL,
      '#currency_symbol' => '$',
      '#parents' => ['type'],
    ];

    $formState = new FormState();
    $completeForm = [];
    $element = MemberTypeCards::processMemberTypeCards($element, $formState, $completeForm);
    // Normally set by FormBuilder's value-resolution during form processing;
    // set explicitly here since this test renders outside a form context.
    foreach ($element['#options'] as $key => $choice) {
      $element[$key]['#value'] = $element[$key]['#default_value'] ?? NULL;
    }

    return $element;
  }

  /**
   * Builds, processes, and renders a single-option member_type_cards element.
   */
  protected function renderCard(array $overrides = []): string {
    $element = $this->buildProcessedElement($overrides);
    $element['#theme_wrappers'] = ['member_type_cards'];

    return (string) $this->container->get('renderer')->renderRoot($element);
  }

  /**
   * A price of 0 renders "Free".
   */
  public function testZeroPriceRendersFree(): void {
    $html = $this->renderCard([
      '#member_types' => ['x' => (object) ['name' => 'Weekend Pass', 'price' => 0]],
    ]);

    $this->assertStringContainsString('>Free<', $html);
  }

  /**
   * A nonzero price renders the currency symbol and the price.
   */
  public function testNonzeroPriceRendersCurrencySymbolAndPrice(): void {
    $html = $this->renderCard([
      '#member_types' => ['x' => (object) ['name' => 'Day Pass', 'price' => '25.00']],
      '#currency_symbol' => '$',
    ]);

    $this->assertStringContainsString('>$25.00<', $html);
    $this->assertStringNotContainsString('>Free<', $html);
  }

  /**
   * A disabled option's card carries the disabled class and ARIA markers.
   *
   * And the "not available" message.
   */
  public function testDisabledOptionRendersDisabledCard(): void {
    $html = $this->renderCard(['#disabled_options' => ['x' => TRUE]]);

    $this->assertStringContainsString('member-type-card--disabled', $html);
    $this->assertStringContainsString('aria-disabled="true"', $html);
    $this->assertStringContainsString('Not available for Member 1', $html);
  }

  /**
   * A non-disabled option's card has none of the disabled markers.
   */
  public function testNonDisabledOptionRendersWithoutDisabledMarkers(): void {
    $html = $this->renderCard();

    $this->assertStringNotContainsString('member-type-card--disabled', $html);
    $this->assertStringNotContainsString('aria-disabled', $html);
    $this->assertStringNotContainsString('Not available for Member 1', $html);
  }

  /**
   * The option matching #default_value renders as selected.
   */
  public function testMatchingDefaultValueRendersSelected(): void {
    $html = $this->renderCard(['#default_value' => 'x']);

    $this->assertStringContainsString('member-type-card--selected', $html);
    $this->assertStringContainsString('aria-checked="true"', $html);
  }

  /**
   * A #default_value that doesn't match this option renders as unselected.
   */
  public function testNonMatchingDefaultValueRendersUnselected(): void {
    $html = $this->renderCard(['#default_value' => 'not-x']);

    $this->assertStringNotContainsString('member-type-card--selected', $html);
    $this->assertStringContainsString('aria-checked="false"', $html);
  }

  /**
   * The card's aria-describedby target matches the description span's id.
   *
   * #card_description is always a non-empty render array (it falls back to
   * the raw option label when the type has no real description), so the
   * card's description span and aria-describedby are always rendered -
   * unlike the underlying radio input, which only gets aria-describedby
   * when there's a genuine, non-empty description.
   */
  public function testCardDescribedbyTargetMatchesRenderedDescriptionId(): void {
    $html = $this->renderCard([
      '#member_types' => ['x' => (object) ['name' => 'X', 'description' => 'A real description.']],
    ]);

    $this->assertMatchesRegularExpression('/aria-describedby="([^"]+)"/', $html, 'Card carries aria-describedby.');
    preg_match('/aria-describedby="([^"]+)"/', $html, $matches);
    $describedbyId = $matches[1];

    $this->assertStringContainsString('id="' . $describedbyId . '" class="member-type-card__description"', $html);
    $this->assertStringContainsString('A real description.', $html);
  }

  /**
   * Day options render inside the selected card only, not other cards.
   */
  public function testDayOptionsRenderOnlyInsideSelectedCard(): void {
    // A trivial stand-in for the real #type => checkboxes render array is
    // enough here: this test proves our own embedding/placement logic (only
    // the selected card carries #day_options), not Drupal core's checkboxes
    // widget, which would need full FormBuilder processing to expand.
    $html = $this->renderCard([
      '#options' => ['x' => 'X', 'y' => 'Y'],
      '#default_value' => 'x',
      'day_options' => ['#markup' => 'Friday only'],
    ]);

    $this->assertStringContainsString('Friday only', $html);
    $this->assertSame(1, substr_count($html, 'member-type-card__day-options'));
  }

  /**
   * No day-options block renders when the type has none.
   */
  public function testDayOptionsAbsentWhenNoneSet(): void {
    $html = $this->renderCard();

    $this->assertStringNotContainsString('member-type-card__day-options', $html);
  }

  /**
   * The preprocess step builds per-type theme suggestions and selection state.
   */
  public function testPreprocessBuildsThemeSuggestionsAndSelectionState(): void {
    $element = $this->buildProcessedElement([
      '#options' => ['x' => 'X', 'MixedCase!' => 'Mixed'],
      '#default_value' => 'x',
    ]);

    $variables = ['element' => $element];
    $this->container->get(ConregThemeHooks::class)->preprocessMemberTypeCards($variables);

    $this->assertSame(['member_type_card__x', 'member_type_card'], $variables['cards']['x']['#theme']);
    $this->assertSame(['member_type_card__mixedcase_', 'member_type_card'], $variables['cards']['MixedCase!']['#theme']);
    $this->assertTrue($variables['cards']['x']['#is_selected']);
    $this->assertFalse($variables['cards']['MixedCase!']['#is_selected']);
    $this->assertTrue($variables['cards']['x']['#is_tabbable']);
    $this->assertFalse($variables['cards']['MixedCase!']['#is_tabbable']);
  }

  /**
   * A member-type-card--{suggestion}.html.twig override is picked up.
   *
   * Proves the file-based per-type template override actually works end to
   * end, not just that the wiring produces the right #theme array. Drupal
   * only auto-discovers suggestion templates like this from the *active
   * theme's* templates/ directory (confirmed via
   * TwigThemeEngine::theme() -> drupal_find_theme_templates(), which is only
   * invoked for the active theme and its base themes, not for arbitrary
   * modules) - so the override lives in a fixture theme, not a fixture
   * module, matching how a real site would actually use this feature.
   */
  public function testPerTypeTemplateOverrideIsPickedUp(): void {
    \Drupal::service('theme_installer')->install(['conreg_test_theme']);
    $this->config('system.theme')->set('default', 'conreg_test_theme')->save();

    $html = $this->renderCard();

    $this->assertStringContainsString('conreg-test-card-override', $html);
    $this->assertStringNotContainsString('member-type-card__radio', $html);
  }

}
