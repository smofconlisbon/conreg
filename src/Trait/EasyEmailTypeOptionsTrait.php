<?php

declare(strict_types=1);

namespace Drupal\conreg\Trait;

use Drupal\Core\GeneratedLink;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Builds select options listing all easy_email_type entities.
 *
 * Used by admin forms that let an event pick which Easy Email template to
 * use for a given purpose (confirmation, member-check, bulk email, ...).
 * Easy Email has no built-in grouping/category for its templates, so
 * every template is offered everywhere and the site maintainer picks
 * sensibly - filtering by a naming convention on the machine name was
 * tried and rejected (the ID can't be changed after a template is
 * created, so it would amount to a permanent "secret" category).
 * Requires the using class to have an injected
 * \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
 * property.
 */
trait EasyEmailTypeOptionsTrait {

  /**
   * Builds a select options array of every easy_email_type entity.
   *
   * @return array
   *   Options keyed by entity ID, labelled with the entity's label.
   */
  protected function easyEmailTypeOptions(): array {
    $options = [];
    $storage = $this->entityTypeManager->getStorage('easy_email_type');
    foreach ($storage->loadMultiple() as $id => $type) {
      $options[$id] = $type->label();
    }
    return $options;
  }

  /**
   * A "Manage email templates" link, opening in a new tab.
   *
   * Meant to be embedded in a select field's #description via a safe
   * placeholder (e.g. $this->t('... @link', ['@link' =>
   * $this->easyEmailTypeManageLink()])), rather than added as a separate
   * form element - keeping it next to the field it explains.
   *
   * @return \Drupal\Core\GeneratedLink
   *   The rendered link markup, already marked safe.
   */
  protected function easyEmailTypeManageLink(): GeneratedLink {
    return Link::fromTextAndUrl(
      $this->t('Manage email templates'),
      Url::fromRoute('entity.easy_email_type.collection', [], ['attributes' => ['target' => '_blank']]),
    )->toString();
  }

}
