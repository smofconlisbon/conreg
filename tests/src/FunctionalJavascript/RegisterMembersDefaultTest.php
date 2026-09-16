<?php

namespace Drupal\Tests\conreg\FunctionalJavascript;

use Behat\Mink\Element\DocumentElement;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\FunctionalJavascriptTests\JSWebAssert;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore martok mogh worf

/**
 * Test to register a member, using default configuration.
 */
#[RunTestsInSeparateProcesses]
class RegisterMembersDefaultTest extends WebDriverTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'options',
    'conreg',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
    'country_mock',
    'stripe_mock',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Store the browser session.
   *
   * @var \Behat\Mink\Session Session
   */
  protected Session $session;

  /**
   * Store the browser page.
   *
   * @var \Behat\Mink\Element\DocumentElement
   */
  protected DocumentElement $page;

  /**
   * Store the Drupal session assertion.
   *
   * @var \Drupal\FunctionalJavascriptTests\JSWebAssert
   */
  protected JSWebAssert $assert;

  /**
   * Set up user with correct permission.
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a user with the required permission.
    $account = User::create([
      'name' => 'test-user',
      'mail' => 'test@example.com',
      'status' => 1,
    ]);
    $account->save();

    // Grant permission.
    user_role_grant_permissions('authenticated', [
      'convention registration',
    ]);

    // Log the user in.
    $this->drupalLogin($account);
  }

  /**
   * Test member registration and payment.
   */
  public function testDefaultMemberRegistration() {
    $this->drupalGet('/members/register');

    $this->session = $this->getSession();
    $this->page = $this->session->getPage();
    $this->assert = $this->assertSession();

    // Assert member fields exist for member 1 but not member 2.
    $this->assert->fieldExists('edit-members-member1-first-name');
    $this->assert->fieldNotExists('edit-members-member2-first-name');

    // Sanity check: form loaded.
    $this->assert->fieldExists('edit-global-member-quantity');

    // Change number of members to 3.
    $this->page->selectFieldOption('edit-global-member-quantity', '3');

    // Wait for Ajax to complete.
    $this->session->wait(
      5000,
      "document.querySelector('#edit-members-member3') !== null"
    );

    // Assert that 3 member subforms are present.
    $members = $this->page->findAll('css', '#members>fieldset');
    $this->assertCount(3, $members);

    // Assert member fields now exist for members 2 and 3.
    $this->assert->fieldExists('edit-members-member2-first-name');
    $this->assert->fieldExists('edit-members-member3-first-name');

    // Set the first member's name.
    $this->setMemberNameAndBadgeName('member1', 'Jean-Luc', 'Picard');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member1', 'F'), 'Jean-Luc');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member1', 'N'), 'Jean-Luc Picard');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member1', 'L'), 'Picard, Jean-Luc');
    // Select badge option for first member.
    $this->setBadgeNameOption('member1', 'N');
    // Change type of first member to attending.
    $this->setMemberType('member1', 'A');
    $this->assert->elementTextEquals('css', "#member1-value", '50.00');
    // Display full name and badge name for first member.
    $this->setMemberDisplayOption('member1', 'F');
    $this->setMemberCommunicationMethod('member1', 'E');
    // Verify that country is selected by mock country service.
    $this->assert->fieldValueEquals('members[member1][address][country]', 'FI');
    // Fill in first member's address (street, city, postcode) and phone.
    $this->setMemberAddress('member1', [
      'street' => '1 Enterprise Way',
      'city' => 'San Francisco',
      'postcode' => '94105',
    ]);
    $this->setMemberPhone('member1', '+1-555-0100');

    // Set the second member's name.
    $this->setMemberNameAndBadgeName('member2', '', 'Worf');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member2', 'F'), '');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member2', 'N'), 'Worf');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member2', 'L'), 'Worf,');
    // Select badge option for second member.
    $this->setBadgeNameOption('member2', 'O', 'Worf, Son of Mogh');
    // Change type of second member to low income.
    $this->setMemberType('member2', 'U');
    $this->assert->elementTextEquals('css', "#member2-value", '25.00');
    // Display member name only for second member.
    $this->setMemberDisplayOption('member2', 'B');
    $this->setMemberCommunicationMethod('member2', 'E');
    // Fill in second member's address (street2, county), covering the
    // address fields member1 left blank.
    $this->setMemberAddress('member2', [
      'street2' => 'Officer Quarters 42',
      'county' => "Qo'noS Province",
    ]);
    // Give member2 their own email, so they get their own confirmation
    // email (member1's is auto-filled from the logged-in user).
    $this->page->fillField('members[member2][email]', 'worf@example.com');

    // Set the third member's name.
    $this->setMemberNameAndBadgeName('member3', 'Alexander', '');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member3', 'F'), 'Alexander');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member3', 'N'), 'Alexander');
    $this->assert->elementTextEquals('css', $this->makeBadgeNameOptionSelector('member3', 'L'), ', Alexander');
    // Select badge option for third member.
    $this->setBadgeNameOption('member3', 'F');
    // Change type of third member to child.
    $this->setMemberType('member3', 'C');
    $this->assert->elementTextEquals('css', "#member3-value", '15.00');
    // Don't display third member at all.
    $this->setMemberDisplayOption('member3', 'N');
    $this->setMemberCommunicationMethod('member3', 'E');

    // Check total price (no need to wait as update will happen at same time as
    // member price update).
    $this->assert->elementTextEquals('css', '#total-value', '90.00');

    $this->page->pressButton('edit-payment-submit');

    // The registration form redirects to the checkout route, which creates
    // a (mocked) Stripe checkout session and renders a mock checkout page
    // (MockStripeService::useMockCheckoutPage() requests this, in place of
    // the real hand-off to a Stripe-hosted checkout page that only a real
    // browser talking to Stripe could complete), showing the amount due and
    // a "Pay now" button.
    $this->assert->waitForElementVisible('css', '#mock-pay-now');
    $this->assert->elementTextContains('css', '#mock-stripe-total', '90.00');
    $this->page->pressButton('mock-pay-now');

    // Pressing the mock "Pay now" button submits back to the checkout
    // route, which finds the mocked session completed, marks the payment
    // paid, and renders the thank-you page.
    $this->assert->waitForElementVisible('xpath', '//h1[contains(text(), "Thank You")]');
    $this->assert->elementTextContains('xpath', '//h1', 'Thank You');

    // Each member with an email address gets their own confirmation email,
    // containing only their own details (see RegistrationConfirmationMailer
    // and MemberDetailsFormatter). Member1's email is the logged-in user's
    // (test@example.com); member2's was filled in above. Member3 has no
    // email, so gets no confirmation - only these two are sent.
    $this->assertMemberConfirmationEmail('test@example.com', [
      'Given name:' . "\t" . 'Jean-Luc',
      'Family name:' . "\t" . 'Picard',
      'Name on badge:' . "\t" . 'Jean-Luc Picard',
      'Membership type:' . "\t" . 'Adult',
      'Address line 1:' . "\t" . '1 Enterprise Way',
      'Town/City:' . "\t" . 'San Francisco',
      'Postal/Zip code:' . "\t" . '94105',
      'Phone (optional):' . "\t" . '+1-555-0100',
      'Price for member:' . "\t" . '€50.00',
      'Member Total:' . "\t" . '€50.00',
      'Total amount paid:' . "\t" . '€90.00',
    ]);
    $this->assertMemberConfirmationEmail('worf@example.com', [
      'Family name:' . "\t" . 'Worf',
      'Name on badge:' . "\t" . 'Worf, Son of Mogh',
      'Membership type:' . "\t" . 'Low Income',
      'Address line 2:' . "\t" . 'Officer Quarters 42',
      "County/State:\tQo'noS Province",
      'Price for member:' . "\t" . '€25.00',
      'Member Total:' . "\t" . '€25.00',
    ]);

    // Each member with an email also gets an admin notification copy (see
    // confirmation.copy_us in conreg.settings.1.yml), sent to the event's
    // from_email. Assert the total mail count too, so an unexpected extra
    // or missing send (e.g. a duplicate, or member3 wrongly getting one)
    // doesn't slip through unnoticed.
    $this->assertCount(2, $this->getMails(['to' => 'default@example.com']), 'Expected one admin notification copy per member with an email.');
    $this->assertCount(4, $this->getMails(), 'Expected exactly 2 member confirmations plus 2 admin copies.');
  }

  /**
   * Assert the confirmation email sent to a member contains the given lines.
   *
   * @param string $to
   *   The member's email address.
   * @param string[] $expectedLines
   *   Lines (e.g. "Label:\tValue") expected in the email's plain-text body.
   */
  protected function assertMemberConfirmationEmail(string $to, array $expectedLines): void {
    $mails = $this->getMails(['to' => $to]);
    $this->assertCount(1, $mails, "Expected exactly one confirmation email sent to $to.");
    $this->assertSame('Thank you for joining Default event', $mails[0]['subject']);

    // Normalize whitespace, since long lines get word-wrapped with a
    // newline inserted mid-value.
    $body = preg_replace('/\s+/', ' ', (string) $mails[0]['plain']);
    foreach ($expectedLines as $line) {
      $this->assertStringContainsString(preg_replace('/\s+/', ' ', $line), $body);
    }
  }

  /**
   * Build the CSS selector for a badge option field.
   */
  protected function makeBadgeNameOptionSelector(string $member, string $option): string {
    return ".form-item-members-$member-badge-name-option input[type=\"radio\"][value=\"$option\"] + label";
  }

  /**
   * Find the fieldset wrapping the given member's fields.
   */
  protected function findMemberFieldset(string $member): NodeElement {
    $index = (int) str_replace('member', '', $member);
    $fieldsets = $this->page->findAll('css', '#members>fieldset');
    return $fieldsets[$index - 1];
  }

  /**
   * Select a member type card, and wait for the price to update.
   */
  protected function setMemberType(string $member, string $type) {
    $fieldset = $this->findMemberFieldset($member);
    // Click the visible card, not the underlying (tabindex="-1") radio
    // input directly - conreg.js binds its selection handler to the card,
    // and ignores clicks that target the input itself.
    $fieldset->find('css', ".member-type-card[data-card-value=\"$type\"]")->click();
    $this->session->wait(
      5000,
      "document.querySelector('#$member-value') !== null && document.querySelector('#$member-value').textContent.trim() !== '0.00'"
    );
  }

  /**
   * Update member name, and wait for badge options to update.
   */
  protected function setMemberNameAndBadgeName(string $member, string $firstName, string $lastName) {
    $this->page->fillField("edit-members-$member-first-name", $firstName);
    $this->page->fillField("edit-members-$member-last-name", $lastName);
    $this->session->wait(
      1000,
      "document.querySelector('.form-item-members-$member-badge-name-option input[type=\"radio\"][value=\"F\"] + label')"
    );
  }

  /**
   * Select badge name option, and if "other", fill in custom badge name.
   */
  protected function setBadgeNameOption(string $member, string $badgeOption, string $badgeName = '') {
    $this->page->find('css', ".form-item-members-$member-badge-name-option input[type='radio'][value='$badgeOption']")->click();
    if ($badgeOption == 'O') {
      $this->session->wait(
        1000,
        "(function () {
          const el = document.querySelector('#edit-members-$member-badge-name');
          return el && el.offsetParent !== null;
        })()",
      );
      $this->page->fillField("edit-members-$member-badge-name", $badgeName);
    }
  }

  /**
   * Set member display option.
   */
  protected function setMemberDisplayOption(string $member, string $display) {
    $this->page->selectFieldOption("members[$member][display]", $display);
  }

  /**
   * Set member communication method (required, has no config default).
   */
  protected function setMemberCommunicationMethod(string $member, string $method) {
    $this->page->selectFieldOption("members[$member][communication_method]", $method);
  }

  /**
   * Fill in the given address fields for a member.
   *
   * @param string $member
   *   The member key, e.g. "member1".
   * @param array $fields
   *   Address field values keyed by field name (e.g. "street", "city").
   */
  protected function setMemberAddress(string $member, array $fields): void {
    foreach ($fields as $field => $value) {
      $this->page->fillField("members[$member][address][$field]", $value);
    }
  }

  /**
   * Fill in a member's phone number.
   */
  protected function setMemberPhone(string $member, string $phone): void {
    $this->page->fillField("members[$member][phone]", $phone);
  }

}
