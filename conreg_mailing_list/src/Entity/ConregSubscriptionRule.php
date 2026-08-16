<?php

declare(strict_types=1);

namespace Drupal\conreg_mailing_list\Entity;

use Drupal\conreg\Member;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\conreg_mailing_list\ConregSubscriptionRuleInterface;
use Drupal\conreg_mailing_list\ConregSubscriptionRuleListBuilder;
use Drupal\conreg_mailing_list\Form\ConregSubscriptionRuleForm;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Defines the subscription rule entity type.
 */
#[ConfigEntityType(
  id: 'conreg_subscription_rule',
  label: new TranslatableMarkup('Subscription rule'),
  label_collection: new TranslatableMarkup('Subscription rules'),
  label_singular: new TranslatableMarkup('subscription rule'),
  label_plural: new TranslatableMarkup('subscription rules'),
  config_prefix: 'conreg_subscription_rule',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'status' => 'status',
    'eid' => 'eid',
  ],
  handlers: [
    'list_builder' => ConregSubscriptionRuleListBuilder::class,
    'form' => [
      'add' => ConregSubscriptionRuleForm::class,
      'edit' => ConregSubscriptionRuleForm::class,
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/conreg/{eid}/subscription-rule',
    'add-form' => '/admin/config/conreg/{eid}/subscription-rule/add',
    'edit-form' => '/admin/config/conreg/{eid}/subscription-rule/{conreg_subscription_rule}/edit',
    'delete-form' => '/admin/config/conreg/{eid}/subscription-rule/{conreg_subscription_rule}/delete',
  ],
  admin_permission: 'administer conreg_subscription_rule',
  label_count: [
    'singular' => '@count subscription rule',
    'plural' => '@count subscription rules',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'eid',
    'provider',
    'list_id',
    'list_name',
    'communication_method',
    'member_option',
  ],
)]
final class ConregSubscriptionRule extends ConfigEntityBase implements ConregSubscriptionRuleInterface {

  /**
   * The example ID.
   */
  protected string $id;

  /**
   * The example label.
   */
  protected string $label;

  /**
   * The example description.
   */
  protected string $description = '';

  /**
   * The mailing list provider plugin ID.
   */
  protected string $provider = '';

  /**
   * The remote mailing list ID.
   */
  protected string $list_id = '';

  /**
   * The remote mailing list name.
   */
  protected string $list_name = '';

  /**
   * Required communication method.
   *
   * "_any" means any communication method.
   */
  protected string $communication_method = '_any';

  /**
   * Required member option.
   *
   * -1 means any member option.
   */
  protected int $member_option = -1;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function setEventId(int $eid): self {
    $this->eid = $eid;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getEventId(): int {
    return $this->eid;
  }

  /**
   * {@inheritdoc}
   */
  public function getProvider(): string {
    return $this->provider;
  }

  /**
   * {@inheritdoc}
   */
  public function getListId(): string {
    return $this->list_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getListName(): string {
    return $this->list_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getCommunicationMethod(): string {
    return $this->communication_method;
  }

  /**
   * {@inheritdoc}
   */
  public function getMemberOption(): int {
    return $this->member_option;
  }

  /**
   * {@inheritdoc}
   */
  public function matches(Member $member): bool {
    if (!$this->status()) {
      return FALSE;
    }

    if (
      $this->communication_method !== '_any' &&
      $member->communication_method !== $this->communication_method
    ) {
      return FALSE;
    }

    if (
      $this->member_option !== -1 &&
      !$member->hasOption($this->member_option)
    ) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function urlRouteParameters($rel) {
    $uri_route_parameters = parent::urlRouteParameters($rel);
    $uri_route_parameters['eid'] = $this->getEventId();
    return $uri_route_parameters;
  }

  /**
   * Before the entity save - check the event ID populated.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Entity storage.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if (empty($this->eid)) {
      throw new \LogicException('Subscription rule must belong to an event.');
    }
  }

}
