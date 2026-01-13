<?php

namespace Drupal\conreg\Service;

/**
 * Interface for country service.
 */
interface CountryServiceInterface {

  /**
   * Get country of user client.
   *
   * @return string
   *   The country code.
   */
  public function getUserCountry(): string;

}
