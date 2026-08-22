<?php

declare(strict_types=1);

namespace Drupal\Tests\conreg_mailing_list\Kernel;

use Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface;
use Drupal\conreg_mailing_list_test\FakeProviderCallRecorder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore testprovider

/**
 * Tests ConregSubscriptionRuleForm.
 *
 * Regression coverage for a bug where the edit form's provider-resolution
 * fallback read $entity->getListId() instead of $entity->getProvider(),
 * causing edit to throw PluginNotFoundException for any saved rule.
 *
 * @property \Drupal\Core\DependencyInjection\ContainerBuilder $container
 */
#[Group('conreg')]
#[RunTestsInSeparateProcesses]
class ConregSubscriptionRuleFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'conreg',
    'conreg_mailing_list',
    'conreg_mailing_list_test',
  ];

  /**
   * Building the edit form for a saved rule doesn't throw.
   */
  public function testEditFormBuildsWithoutException(): void {
    $rule = $this->createRule();

    $form = $this->container->get('entity.form_builder')->getForm($rule, 'edit');

    $this->assertArrayHasKey('provider', $form);
  }

  /**
   * The provider is correctly resolved before fetching the list options.
   */
  public function testEditFormProviderAndListIdDefaults(): void {
    $rule = $this->createRule();

    $form = $this->container->get('entity.form_builder')->getForm($rule, 'edit');

    $this->assertSame('testprovider', $form['provider']['#default_value']);
    $this->assertSame('1', $form['list_id']['#default_value']);
    $this->assertArrayHasKey(1, $form['list_id']['#options']);
  }

  /**
   * The form still renders, with an empty list, if the provider fails.
   */
  public function testEditFormDegradesGracefullyWhenGetListsThrows(): void {
    $rule = $this->createRule('fake_provider');
    $this->container->get(FakeProviderCallRecorder::class)->getListsFailure = TRUE;

    $form = $this->container->get('entity.form_builder')->getForm($rule, 'edit');

    $this->assertSame([], $form['list_id']['#options']);

    $warnings = $this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_WARNING);
    $this->assertNotEmpty($warnings);
  }

  /**
   * The list_name field is no longer rendered.
   */
  public function testListNameFieldNoLongerRendered(): void {
    $rule = $this->createRule();

    $form = $this->container->get('entity.form_builder')->getForm($rule, 'edit');

    $this->assertArrayNotHasKey('list_name', $form);
  }

  /**
   * Submitting the form sets list_name from the selected list_id's label.
   */
  public function testSubmittingFormSetsListNameFromSelectedOption(): void {
    $rule = $this->createRule('fake_provider');

    $this->submitEditForm($rule, [
      'provider' => 'fake_provider',
      'list_id' => '1',
    ]);

    $this->assertSame('Fake list', $this->reloadRule($rule->id())->getListName());
  }

  /**
   * The list_name is left unchanged if the provider is unavailable on submit.
   */
  public function testListNameUnchangedWhenProviderUnavailable(): void {
    $rule = $this->createRule('fake_provider');
    $rule->set('list_name', 'Known Good Name');
    $rule->save();

    $this->container->get(FakeProviderCallRecorder::class)->getListsFailure = TRUE;

    $this->submitEditForm($rule, [
      'provider' => 'fake_provider',
      'list_id' => '1',
    ]);

    $this->assertSame('Known Good Name', $this->reloadRule($rule->id())->getListName());
  }

  /**
   * Creates and saves a subscription rule using the given provider.
   */
  protected function createRule(string $provider = 'testprovider'): ConregSubscriptionRuleInterface {
    $rule = $this->container->get('entity_type.manager')
      ->getStorage('conreg_subscription_rule')
      ->create([
        'id' => 'edit_test_rule',
        'label' => 'Edit Test Rule',
        'eid' => 1,
        'provider' => $provider,
        'list_id' => '1',
      ]);
    $rule->save();
    return $rule;
  }

  /**
   * Submits the edit form for a rule with the given field overrides.
   */
  protected function submitEditForm(ConregSubscriptionRuleInterface $rule, array $values): void {
    $formObject = $this->container->get('entity_type.manager')
      ->getFormObject('conreg_subscription_rule', 'edit');
    $formObject->setEntity($rule);

    $formState = (new FormState())->setValues($values + [
      'label' => $rule->label(),
      'id' => $rule->id(),
      'status' => 1,
      'description' => $rule->getDescription(),
      'communication_method' => $rule->getCommunicationMethod(),
      'member_option' => $rule->getMemberOption(),
      // Matches the "Save" submit button's #value, so FormBuilder resolves
      // it as the triggering element and runs its #submit handlers
      // (EntityForm's ::submitForm/::save are wired to the button, not the
      // form itself).
      'op' => 'Save',
    ]);

    $this->container->get('form_builder')->submitForm($formObject, $formState);
  }

  /**
   * Reloads a subscription rule by ID, bypassing the static entity cache.
   */
  protected function reloadRule(string $id): ConregSubscriptionRuleInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('conreg_subscription_rule');
    $storage->resetCache([$id]);
    /** @var \Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface $rule */
    $rule = $storage->load($id);
    return $rule;
  }

}
