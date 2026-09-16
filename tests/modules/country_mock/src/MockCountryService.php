<?php

namespace Drupal\country_mock;

use Drupal\conreg\Service\CountryServiceInterface;

/**
 * Country service for Simple Convention Registration.
 */
class MockCountryService implements CountryServiceInterface {

  /**
   * Mock of get country - always returns Finland.
   */
  public function getUserCountry(): string {
    return 'FI';
  }

}
