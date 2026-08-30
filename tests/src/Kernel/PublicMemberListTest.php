<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\conreg\Controller\ConregController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

// cspell:ignore Bravo Alpha

/**
 * Tests that member listing page config options control visible columns.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class PublicMemberListTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install tables required by ConReg.
    $this->installSchema('conreg', ['conreg_members', 'conreg_events']);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();

    // Create mocked LanguageManager service, needed by ConregOptions.
    $language = new Language(['id' => 'en']);

    $langManager = $this->createMock(LanguageManagerInterface::class);
    $langManager->method('getCurrentLanguage')
      ->willReturn($language);
    $langManager->method('getDefaultLanguage')
      ->willReturn($language);

    $this->container->set('language_manager', $langManager);

    Database::getConnection()->insert('conreg_members')
      ->fields([
        'eid' => 1,
        'member_no' => 1,
        'language' => 'en',
        'is_approved' => 1,
        'first_name' => 'Test',
        'last_name' => 'User',
        'badge_name' => 'Test User',
        'badge_type' => 'A',
        'display' => 'F',
        'country' => 'IE',
        'is_deleted' => 0,
      ])
      ->execute();
  }

  /**
   * Insert an additional approved, publicly-listed member.
   */
  protected function createMember(int $memberNo, string $name): void {
    Database::getConnection()->insert('conreg_members')
      ->fields([
        'eid' => 1,
        'member_no' => $memberNo,
        'language' => 'en',
        'is_approved' => 1,
        'first_name' => $name,
        'last_name' => 'User',
        'badge_name' => $name . ' User',
        'badge_type' => 'A',
        'display' => 'F',
        'country' => 'IE',
        'is_deleted' => 0,
      ])
      ->execute();
  }

  /**
   * Render a render array and extract each row's cell text keyed by header.
   *
   * Drupal's table theming matches row cells to headers positionally, not
   * by array key - see ThemePreprocess::preprocessTable(), which iterates
   * both #header and each row's cells with a plain foreach and never
   * cross-references the two by key. So the only reliable way to prove a
   * hidden column hasn't shifted the remaining columns out of alignment is
   * to render the real table markup and read cells back out by position,
   * exactly as a browser would.
   *
   * @return array
   *   A list of rows, each an associative array of header label => cell
   *   text, in column order.
   */
  protected function renderTableRowsByHeader(array $table): array {
    $html = $this->render($table);

    $document = new \DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new \DOMXPath($document);

    $headers = [];
    foreach ($xpath->query('//thead//th') as $th) {
      // Sortable columns render their label as the link's own text, followed
      // by a visually-hidden "Sort ascending/descending" indicator nested in
      // a <span> inside that same link - so only the link's direct text
      // nodes (not its descendants) are the actual column label.
      $target = $xpath->query('.//a', $th)->item(0) ?? $th;
      $label = '';
      foreach ($xpath->query('text()', $target) as $textNode) {
        $label .= $textNode->textContent;
      }
      $headers[] = trim($label);
    }

    $rows = [];
    foreach ($xpath->query('//tbody//tr') as $tr) {
      $row = [];
      $cells = $xpath->query('.//td', $tr);
      foreach ($cells as $index => $td) {
        $row[$headers[$index]] = trim($td->textContent);
      }
      $rows[] = $row;
    }

    return $rows;
  }

  /**
   * Call the controller the same way the route would.
   */
  protected function callMemberList($eid = 1) {
    $request = Request::create("/members/list/$eid");
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $controller = ConregController::create($this->container);
    return $controller->memberList($eid);
  }

  /**
   * Unchecking "Show member countries" hides the country column.
   */
  public function testShowCountriesFalseHidesCountryColumn(): void {
    $this->config('conreg.settings.1')
      ->set('member_listing_page.show_countries', FALSE)
      ->save();

    $content = $this->callMemberList();

    $this->assertArrayNotHasKey('member_country', $content['table']['#header']);
    foreach ($content['table']['#rows'] as $row) {
      $this->assertArrayNotHasKey('country', $row);
    }
  }

  /**
   * Leaving "Show member countries" checked keeps the country column.
   */
  public function testShowCountriesTrueShowsCountryColumn(): void {
    $this->config('conreg.settings.1')
      ->set('member_listing_page.show_countries', TRUE)
      ->save();

    $content = $this->callMemberList();

    $this->assertArrayHasKey('member_country', $content['table']['#header']);
  }

  /**
   * Unchecking "Show member number" hides the member number column.
   */
  public function testShowMemberNoFalseHidesMemberNumberColumn(): void {
    $this->config('conreg.settings.1')
      ->set('member_listing_page.show_member_no', FALSE)
      ->save();

    $content = $this->callMemberList();

    $this->assertArrayNotHasKey('member_no', $content['table']['#header']);
    foreach ($content['table']['#rows'] as $row) {
      $this->assertArrayNotHasKey('member_no', $row);
    }
  }

  /**
   * Leaving "Show member number" checked keeps the member number column.
   */
  public function testShowMemberNoTrueShowsMemberNumberColumn(): void {
    $this->config('conreg.settings.1')
      ->set('member_listing_page.show_member_no', TRUE)
      ->save();

    $content = $this->callMemberList();

    $this->assertArrayHasKey('member_no', $content['table']['#header']);
  }

  /**
   * With no config saved, the member number column defaults to shown.
   */
  public function testShowMemberNoUnsetDefaultsToShown(): void {
    $content = $this->callMemberList();

    $this->assertArrayHasKey('member_no', $content['table']['#header']);
  }

  /**
   * Hiding the member number column doesn't shift the country column.
   *
   * Regression guard: header cells and row cells are matched purely by
   * position (see renderTableRowsByHeader() docblock), so a fix that drops
   * 'member_no' from #header but forgets to drop it from the row - or vice
   * versa - would silently shift every later column's data one cell to the
   * left instead of raising an error.
   */
  public function testMemberNoColumnHiddenPreservesCountryColumnAlignment(): void {
    $this->config('conreg.settings.1')
      ->set('member_listing_page.show_member_no', FALSE)
      ->set('member_listing_page.show_countries', TRUE)
      ->save();

    $content = $this->callMemberList();

    $this->assertArrayNotHasKey('member_no', $content['table']['#header']);

    $rows = $this->renderTableRowsByHeader($content['table']);
    $this->assertCount(1, $rows);
    $this->assertSame(['Name', 'Type', 'Country'], array_keys($rows[0]));
    $this->assertSame('Test User', $rows[0]['Name']);
    $this->assertSame('Attending', $rows[0]['Type']);
    $this->assertSame('Ireland', $rows[0]['Country']);
  }

  /**
   * The default member-number sort order still applies with the column hidden.
   *
   * The sort key is built from a local variable independent of whether the
   * column is displayed, but that's an implementation detail worth locking
   * in: hiding the column must not be implemented in a way that also drops
   * the members' natural ordering.
   */
  public function testDefaultSortByMemberNoStillOrdersRowsWhenColumnHidden(): void {
    $this->createMember(3, 'Bravo');
    $this->createMember(2, 'Alpha');

    $this->config('conreg.settings.1')
      ->set('member_listing_page.show_member_no', FALSE)
      ->save();

    $content = $this->callMemberList();

    $names = array_column($content['table']['#rows'], 'name');
    $this->assertSame(['Test User', 'Alpha User', 'Bravo User'], $names);
  }

}
