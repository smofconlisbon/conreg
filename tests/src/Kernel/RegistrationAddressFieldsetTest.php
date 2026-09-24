<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Registration;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests grouping the registration form's address fields into a fieldset.
 *
 * Regression coverage for #3596641: the address fields (street, street2,
 * city, county, postcode, country) and the "Same as member 1" checkbox used
 * to render as flat, ungrouped elements. They're now wrapped together in a
 * fieldset with a per-member-class-configurable legend - but only when a
 * legend is actually configured, so sites that leave it blank keep the
 * original plain wrapper. Split out of FormBuildTest.php since that file is
 * meant to stay a lightweight per-route smoke test.
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class RegistrationAddressFieldsetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'datetime',
    'key',
    'conreg',
    'file',
    'text',
    'filter',
    'jquery_ui_resizable',
    'easy_email',
  ];

  /**
   * Set up database tables and config for testing forms.
   */
  protected function setUp(): void {
    parent::setUp();

    // Required for #type = datetime.
    $this->installConfig(['system', 'datetime']);

    // Install the database schema for conreg.
    $this->installSchema('conreg', [
      'conreg_events',
      'conreg_members',
      'conreg_member_addons',
      'conreg_member_options',
    ]);
    $this->installConfig(['conreg']);
    Database::getConnection()->insert('conreg_events')
      ->fields([
        'event_name' => 'Test event',
        'is_open' => 1,
      ])
      ->execute();
  }

  /**
   * Builds a Registration form instance for direct method calls.
   */
  protected function createRegistrationForm(): Registration {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(Registration::class);
  }

  /**
   * Sets the "Address" section heading for the default member class.
   */
  protected function setAddressHeading(string $heading): void {
    $this->container->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member.classes.Default.fields.address_heading', $heading)
      ->save();
  }

  /**
   * With a heading configured, the address block renders as a fieldset.
   *
   * The default install config already sets an "Address" heading, so no
   * extra setup is needed to hit this path.
   */
  public function testAddressFieldsWrappedInFieldsetWhenHeadingConfigured(): void {
    $form = $this->createRegistrationForm()->buildForm([], new FormState(), 1);

    $address = $form['members']['member1']['address'];
    $this->assertSame('fieldset', $address['#type']);
    $this->assertEquals('Address', (string) $address['#title']);
    // The fields themselves are unaffected by the wrapping.
    $this->assertArrayHasKey('street', $address);
    $this->assertArrayHasKey('country', $address);
  }

  /**
   * With no heading configured, the address block stays a plain wrapper.
   *
   * Clearing the label should not just hide the legend - it should remove
   * the fieldset entirely, leaving the original div-only wrapper.
   */
  public function testAddressFieldsNotWrappedInFieldsetWhenHeadingBlank(): void {
    $this->setAddressHeading('');

    $form = $this->createRegistrationForm()->buildForm([], new FormState(), 1);

    $address = $form['members']['member1']['address'];
    $this->assertArrayNotHasKey('#type', $address);
    // The fields are still all present - only the wrapping changed.
    $this->assertArrayHasKey('street', $address);
    $this->assertArrayHasKey('country', $address);
  }

  /**
   * The "Same as member 1" checkbox is grouped inside the address block.
   *
   * It only exists for members after the first, and should now be a child
   * of the address element (so it's visually grouped with the fields it
   * controls) rather than a sibling of it.
   */
  public function testSameAddressCheckboxNestedInsideAddressForLaterMembers(): void {
    $formState = new FormState();
    $formState->setValues(['global' => ['member_quantity' => 2]]);
    $registration = $this->createRegistrationForm();
    $form = $registration->buildForm([], $formState, 1);

    $this->assertArrayNotHasKey('same_address', $form['members']['member1']['address']);

    $member2Address = $form['members']['member2']['address'];
    $this->assertArrayHasKey('same_address', $member2Address);
    $this->assertSame('checkbox', $member2Address['same_address']['#type']);
  }

  /**
   * Regression coverage for #3596656: unchecking "same" restores the fields.
   *
   * The fields used to be omitted from the form entirely while "same" was
   * checked, and an AJAX callback re-rendered the wrapper to show them
   * again on uncheck - but that callback only ever fired reliably on check,
   * not uncheck, permanently hiding the fields. The fix replaces the AJAX
   * round trip with #states, so showing and hiding both happen client-side
   * via the same mechanism and can't drift apart from each other.
   */
  public function testAddressFieldsUseStatesToToggleOnSameAddressCheckbox(): void {
    $formState = new FormState();
    $formState->setValues(['global' => ['member_quantity' => 2]]);
    $form = $this->createRegistrationForm()->buildForm([], $formState, 1);

    $expectedStates = [
      'visible' => [
        ':input[name="members[member2][address][same_address]"]' => ['checked' => FALSE],
      ],
    ];
    $member2Address = $form['members']['member2']['address'];
    foreach (['street', 'street2', 'city', 'county', 'postcode', 'country'] as $field) {
      $this->assertArrayHasKey('#states', $member2Address[$field], "Field \"$field\" should declare #states.");
      $this->assertSame($expectedStates, $member2Address[$field]['#states']);
    }

    // The fields must still actually be present (not omitted) so #states
    // has something to toggle, and so previously-entered values survive an
    // accidental check/uncheck round trip.
    $this->assertArrayHasKey('street', $member2Address);
  }

  /**
   * Member 1 never has a "same as member 1" checkbox to depend on.
   *
   * #states referencing a selector for a field that doesn't exist would be
   * at best dead weight and at worst confusing, so member 1's address
   * fields (which can never have "same_address" as a sibling) must not
   * declare #states at all.
   */
  public function testAddressFieldsHaveNoStatesForMemberOne(): void {
    $form = $this->createRegistrationForm()->buildForm([], new FormState(), 1);

    $member1Address = $form['members']['member1']['address'];
    foreach (['street', 'street2', 'city', 'county', 'postcode', 'country'] as $field) {
      $this->assertArrayNotHasKey('#states', $member1Address[$field], "Field \"$field\" should not declare #states.");
    }
  }

  /**
   * With no "same address" label configured, later members get no checkbox.
   *
   * Their address fields must not declare #states referencing it, the same
   * as member 1's.
   */
  public function testAddressFieldsHaveNoStatesWhenSameAddressLabelBlank(): void {
    $this->container->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member.classes.Default.fields.same_address', '')
      ->save();

    $formState = new FormState();
    $formState->setValues(['global' => ['member_quantity' => 2]]);
    $form = $this->createRegistrationForm()->buildForm([], $formState, 1);

    $member2Address = $form['members']['member2']['address'];
    $this->assertArrayNotHasKey('same_address', $member2Address);
    foreach (['street', 'street2', 'city', 'county', 'postcode', 'country'] as $field) {
      $this->assertArrayNotHasKey('#states', $member2Address[$field], "Field \"$field\" should not declare #states.");
    }
  }

  /**
   * Required-ness of member 2+ address fields must still track the checkbox.
   *
   * The fields are now always built rather than omitted, so #required has
   * to carry the "same as member 1" logic that omission used to provide
   * implicitly.
   */
  public function testAddressFieldsRequiredReflectsSameAddressSubmittedValue(): void {
    $this->container->get('config.factory')
      ->getEditable('conreg.settings.1')
      ->set('member.classes.Default.mandatory.street', 1)
      ->save();

    $formState = new FormState();
    $formState->setValues([
      'global' => ['member_quantity' => 2],
      'members' => [
        'member2' => [
          'address' => ['same_address' => 1],
        ],
      ],
    ]);
    $form = $this->createRegistrationForm()->buildForm([], $formState, 1);

    // Member 1 is always required regardless of the checkbox.
    $this->assertTrue($form['members']['member1']['address']['street']['#required']);
    // Member 2 ticked "same as member 1", so its own fields aren't required.
    $this->assertFalse($form['members']['member2']['address']['street']['#required']);
  }

}
