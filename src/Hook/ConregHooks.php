<?php

namespace Drupal\conreg\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for conreg.
 */
class ConregHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      // Case 'conreg_register':
      // Help text for the simple page registered for this path.
      // return t('Please register your details.');.
      case 'help.page.conreg':
        // Help text for the admin section, using the module name in the path.
        return $this->t("Configure settings for convention registration.");
    }
  }

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public static function pageAttachments(&$variables) {
    // Include Icon CSS if Drupal earlier than 11.1 - after this use Icon API.
    if (version_compare(\Drupal::VERSION, '11.1', '<')) {
      $variables['#attached']['library'][] = 'conreg/icon';
    }
  }

}
