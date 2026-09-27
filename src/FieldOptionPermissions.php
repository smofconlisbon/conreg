<?php

namespace Drupal\conreg;

use Drupal\conreg\Service\EventStorage;

/**
 * List options for ConReg.
 */
class FieldOptionPermissions {

  /**
   * No construction required.
   */
  public function __construct() {}

  /**
   * Get permissions for ConReg field options.
   *
   * @return array
   *   Permissions array.
   */
  public static function permissions() {
    $permissions = [];

    $events = \Drupal::service(EventStorage::class)->eventOptions();
    foreach ($events as $event) {
      $fieldOptions = FieldOptions::getFieldOptions($event['eid']);
      foreach ($fieldOptions->getFieldOptionList() as $option) {
        // The shared title prefix keeps these together on the Permissions
        // page, which sorts each module's permissions by title. Titles with
        // the same text are compared by their arguments in order, so %event
        // comes first to sort by event, then option. Option titles may
        // contain HTML for the registration form, which would otherwise
        // show as escaped tags here.
        $permissions += [
          'view field option ' . $option['option_id'] . ' event ' . $event['eid'] => [
            'title' => t('Member option – %event: %option',
            [
              '%event' => $event['event_name'],
              '%option' => strip_tags($option['option_title']),
            ]),
            'description' => t('See which members chose this option on the Member Options page. Also requires "View membership options".'),
          ],
        ];
      }
    }

    return $permissions;
  }

}
