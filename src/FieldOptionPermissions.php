<?php

namespace Drupal\conreg;

/**
 * List options for Simple Convention Registration.
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

    $events = EventStorage::eventOptions();
    foreach ($events as $event) {
      $fieldOptions = FieldOptions::getFieldOptions($event['eid']);
      foreach ($fieldOptions->getFieldOptionList() as $option) {
        $permissions += [
          'view field option ' . $option['option_id'] . ' event ' . $event['eid'] => [
            'title' => t('View data for field option %option for event %event',
            [
              '%option' => $option['option_title'],
              '%event' => $event['event_name'],
            ]),
          ],
        ];
      }
    }

    return $permissions;
  }

}
