<?php

namespace Drupal\Tests\conreg\Unit\Form\Admin;

use Drupal\Core\Cache\CacheTagsInvalidator;
use Drupal\Core\Form\FormState;
use Drupal\conreg\Form\Admin\MemberClasses;
use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\EventStorage;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests saving member classes.
 */
#[Group('conreg')]
class MemberClassesTest extends UnitTestCase {

  /**
   * The form under test.
   *
   * @var \Drupal\conreg\Form\Admin\MemberClasses
   */
  protected MemberClasses $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $cacheInvalidator = $this->createMock(CacheTagsInvalidator::class);
    $eventStorage = $this->createMock(EventStorage::class);
    $conregOptions = $this->createMock(ConregOptions::class);

    $this->form = new MemberClasses($cacheInvalidator, $eventStorage, $conregOptions);
  }

  /**
   * Saving a class whose stored data has no "extras" property must not warn.
   *
   * ConregOptions::memberClasses() only sets categories that are actually
   * present in config, and the default/normal "member.classes.*" config
   * never contains an "extras" key (there is no form UI for it either) - so
   * $class->extras is never set on the class object that
   * updateMemberClasses() receives. This reproduces the reported bug's
   * "Undefined property: stdClass::$extras" and "foreach() argument must be
   * of type array|object, null given" warnings, which fail the test because
   * phpunit.xml.dist sets failOnWarning="true".
   */
  public function testUpdateMemberClassesWithoutExtrasDoesNotWarn(): void {
    $formState = new FormState();
    $formState->set('eid', 1);
    $formState->set('fieldLabels', [
      'first_name' => (object) ['type' => 'textfield'],
    ]);
    $formState->set('mandatoryLabels', [
      'first_name' => 'First name mandatory',
    ]);
    $formState->set('maxLengthLabels', [
      'first_name' => 'First name maximum length',
    ]);

    $class = (object) [
      'name' => 'Default',
      'fields' => (object) ['first_name' => 'Given name'],
      'mandatory' => (object) ['first_name' => 0],
      'max_length' => (object) ['first_name' => NULL],
    ];
    $memberClasses = (object) [
      'classes' => ['Default' => $class],
      'options' => ['Default' => 'Default'],
    ];

    $vals = [
      'Default' => [
        'class' => ['name' => 'Default'],
        'labels' => ['first_name' => 'Given name'],
        'mandatory' => ['first_name' => 0],
        'max_length' => ['first_name' => ''],
      ],
    ];

    $method = new \ReflectionMethod(MemberClasses::class, 'updateMemberClasses');
    $method->invokeArgs($this->form, [&$memberClasses, $vals, $formState]);

    $this->assertSame('Default', $memberClasses->classes['Default']->name);
    $this->assertSame('Given name', $memberClasses->classes['Default']->fields->first_name);
  }

}
