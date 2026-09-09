<?php

namespace Drupal\conreg;

use Drupal\Core\Config\ImmutableConfig;

/**
 * List options for ConReg.
 */
class ConregConfig {

  /**
   * Get current event config.
   *
   * @param int $eid
   *   Event ID.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config object.
   */
  public static function getConfig(int $eid): ImmutableConfig {
    return \Drupal::config('conreg.settings.' . $eid);
  }

}
