<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg\Kernel;

use Drupal\conreg\Form\Registration;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
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
    $this->assertSame([$registration, 'updateMemberAddressCallback'], $member2Address['same_address']['#ajax']['callback']);
  }

  /**
   * The "same address" AJAX callback still targets the right wrapper.
   *
   * The callback re-renders each later member's address element and swaps
   * it into #memberAddress{n} - that id lives on the outer div, which now
   * wraps a fieldset instead of being ungrouped, so this pins down that the
   * AJAX wiring survived the restructuring.
   */
  public function testUpdateMemberAddressCallbackTargetsMemberAddressWrapper(): void {
    $formState = new FormState();
    $formState->setValues(['global' => ['member_quantity' => 2]]);
    $registration = $this->createRegistrationForm();
    $form = $registration->buildForm([], $formState, 1);

    $response = $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $registration->updateMemberAddressCallback($form, $formState),
    );

    $this->assertInstanceOf(AjaxResponse::class, $response);
    $commands = $response->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('#memberAddress2', $commands[0]['selector']);

    // The swapped-in markup is the whole restructured block: the fieldset
    // and its legend, the regrouped checkbox, and the address fields -
    // not just the fields it had before the checkbox moved inside.
    $data = (string) $commands[0]['data'];
    $this->assertStringContainsString('fieldset-legend">Address<', $data);
    $this->assertStringContainsString('Same as member 1', $data);
    $this->assertStringContainsString('Address line 1', $data);
  }

}
