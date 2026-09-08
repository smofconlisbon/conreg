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

}
