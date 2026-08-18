<?php

namespace Drupal\Tests\conreg\Unit\Form\Admin;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\conreg\Form\Admin\EventConfig;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests event configuration validation.
 */
#[Group('conreg')]
class EventConfigTest extends UnitTestCase {

  /**
   * The form under test.
   *
   * @var \Drupal\Tests\conreg\Unit\Form\Admin\TestEventConfig
   */
  protected TestEventConfig $form;

  /**
   * Warning messages captured from the mocked messenger.
   *
   * @var string[]
   */
  protected array $warnings;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $emailValidator = $this->createMock(EmailValidatorInterface::class);
    $emailValidator->method('isValid')->willReturnCallback(
      static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE
    );

    $this->warnings = [];
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->method('addWarning')->willReturnCallback(
      function ($message): void {
        $this->warnings[] = (string) $message;
      }
    );

    $this->form = new TestEventConfig($emailValidator, $messenger);
    $this->form->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Tests membership option validation.
   *
   * @param string $groups
   *   The option group configuration.
   * @param string $options
   *   The membership option configuration.
   * @param string[] $memberClasses
   *   Valid member class IDs.
   * @param string|null $expectedError
   *   An expected error substring, or NULL when validation should pass.
   */
  #[DataProvider('memberOptionValidationProvider')]
  public function testMemberOptionValidation(
    string $groups,
    string $options,
    array $memberClasses,
    ?string $expectedError,
  ): void {
    $formState = new FormState();
    $groupIds = $this->form->validateOptionGroupsForTest($groups, $formState);
    $this->form->validateOptionsForTest(
      $options,
      $groupIds,
      array_fill_keys($memberClasses, TRUE),
      $formState,
    );

    $errors = implode("\n", array_map(
      static fn($error): string => (string) $error,
      $formState->getErrors(),
    ));

    if ($expectedError === NULL) {
      $this->assertSame('', $errors);
    }
    else {
      $this->assertStringContainsString($expectedError, $errors);
    }
  }

  /**
   * An option with no member class assigned warns that it will never show.
   *
   * This is deliberately not a validation error - the configuration is
   * allowed to be saved - but the option is unreachable on the registration
   * form until a class is assigned, so it should be flagged.
   */
  public function testEmptyMemberClassesWarns(): void {
    $groups = '1|checkboxes|volunteer|Volunteer opportunities|0|1';
    $formState = new FormState();

    $groupIds = $this->form->validateOptionGroupsForTest($groups, $formState);
    $this->form->validateOptionsForTest(
      '1|1|Help before the event||0|0||0|0|',
      $groupIds,
      ['Default' => TRUE],
      $formState,
    );

    $this->assertSame([], $formState->getErrors());
    $this->assertCount(1, $this->warnings);
    $this->assertStringContainsString('Help before the event', $this->warnings[0]);
    $this->assertStringContainsString('will never appear on the registration form', $this->warnings[0]);
  }

  /**
   * An option with a member class assigned doesn't trigger the warning.
   */
  public function testAssignedMemberClassDoesNotWarn(): void {
    $groups = '1|checkboxes|volunteer|Volunteer opportunities|0|1';
    $formState = new FormState();

    $groupIds = $this->form->validateOptionGroupsForTest($groups, $formState);
    $this->form->validateOptionsForTest(
      '1|1|Help before the event||0|0|Default|0|0|',
      $groupIds,
      ['Default' => TRUE],
      $formState,
    );

    $this->assertSame([], $this->warnings);
  }

  /**
   * Provides membership option validation cases.
   *
   * @return array
   *   Test cases keyed by a description.
   */
  public static function memberOptionValidationProvider(): array {
    $groups = '1|checkboxes|volunteer|Volunteer opportunities|0|1';
    $option = '1|1|Help before the event||0|0|Default|0|0|';

    return [
      'valid configuration' => [
        implode("\r\n", [
          $groups,
          '2|textfields|dietary|Dietary requirements|1|0',
        ]),
        implode("\r\n", [
          $option,
          '2|2|Dietary requirements|Details|1|-10|Default,VIP|1|1|notify@example.com',
        ]),
        ['Default', 'VIP'],
        NULL,
      ],
      'group field count' => [
        '1|checkboxes|volunteer|Volunteer opportunities|0',
        '',
        ['Default'],
        'exactly 6',
      ],
      'duplicate group ID' => [
        $groups . "\n1|textfields|notes|Notes|0|1",
        '',
        ['Default'],
        'duplicate group ID 1',
      ],
      'invalid group ID' => [
        'one|checkboxes|volunteer|Volunteer opportunities|0|1',
        '',
        ['Default'],
        'invalid group ID',
      ],
      'invalid field type' => [
        '1|radios|volunteer|Volunteer opportunities|0|1',
        '',
        ['Default'],
        'invalid field type',
      ],
      'missing group title' => [
        '1|checkboxes|volunteer||0|1',
        '',
        ['Default'],
        'requires both a field name and a title',
      ],
      'invalid group visibility' => [
        '1|checkboxes|volunteer|Volunteer opportunities|2|1',
        '',
        ['Default'],
        'local/global and private/public',
      ],
      'option field count' => [
        $groups,
        '1|1|Help before the event||0|0|Default|0|0',
        ['Default'],
        'exactly 10',
      ],
      'duplicate option ID' => [
        $groups,
        $option . "\n1|1|Second option||0|0|Default|0|0|",
        ['Default'],
        'duplicate option ID 1',
      ],
      'invalid option ID' => [
        $groups,
        'one|1|Help before the event||0|0|Default|0|0|',
        ['Default'],
        'invalid option ID',
      ],
      'undefined group ID' => [
        $groups,
        '1|2|Help before the event||0|0|Default|0|0|',
        ['Default'],
        'undefined group ID 2',
      ],
      'undefined member class' => [
        $groups,
        '1|1|Help before the event||0|0|Unknown|0|0|',
        ['Default'],
        'undefined member class "Unknown"',
      ],
      'empty member classes list is valid' => [
        $groups,
        '1|1|Help before the event||0|0||0|0|',
        ['Default'],
        NULL,
      ],
      'trailing comma in member classes list is valid' => [
        $groups,
        '1|1|Help before the event||0|0|Default,|0|0|',
        ['Default'],
        NULL,
      ],
      'missing option title' => [
        $groups,
        '1|1|||0|0|Default|0|0|',
        ['Default'],
        'requires an option title',
      ],
      'invalid detail required' => [
        $groups,
        '1|1|Help before the event||2|0|Default|0|0|',
        ['Default'],
        '0 or 1 for detail required',
      ],
      'invalid must be checked' => [
        $groups,
        '1|1|Help before the event||0|0|Default|2|0|',
        ['Default'],
        '0 or 1 for must be checked',
      ],
      'invalid private' => [
        $groups,
        '1|1|Help before the event||0|0|Default|0|2|',
        ['Default'],
        '0 or 1 for private',
      ],
      'invalid weight' => [
        $groups,
        '1|1|Help before the event||0|heavy|Default|0|0|',
        ['Default'],
        'invalid weight',
      ],
      'invalid notification email' => [
        $groups,
        '1|1|Help before the event||0|0|Default|0|0|not-an-email',
        ['Default'],
        'invalid notification email',
      ],
    ];
  }

}

/**
 * Exposes EventConfig validation methods for unit testing.
 */
class TestEventConfig extends EventConfig {

  /**
   * Constructs a test event configuration form.
   */
  public function __construct(EmailValidatorInterface $emailValidator, MessengerInterface $messenger) {
    $this->emailValidator = $emailValidator;
    $this->setMessenger($messenger);
  }

  /**
   * Calls the option group validator.
   */
  public function validateOptionGroupsForTest(
    string $value,
    FormStateInterface $formState,
  ): array {
    return $this->validateOptionGroups($value, $formState);
  }

  /**
   * Calls the membership option validator.
   */
  public function validateOptionsForTest(
    string $value,
    array $groupIds,
    array $memberClassIds,
    FormStateInterface $formState,
  ): void {
    $this->validateOptions(
      $value,
      $groupIds,
      $memberClassIds,
      $formState,
    );
  }

}
