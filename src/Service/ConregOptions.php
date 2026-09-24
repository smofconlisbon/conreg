<?php

namespace Drupal\conreg\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Locale\CountryManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * List options for ConReg.
 */
class ConregOptions {

  /**
   * In-memory per-event memoization of member upgrades.
   *
   * @var array
   */
  protected array $memberUpgradesCache = [];

  /**
   * Constructs a new ConregOptions service.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache bin.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
    protected MemberStorage $memberStorage,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Load the event's configuration.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config object.
   */
  protected function loadConfig(int $eid): ImmutableConfig {
    return $this->configFactory->get('conreg.settings.' . $eid);
  }

  /**
   * Function to get cache ID for member classes.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return string
   *   The cache identifier.
   */
  protected function getMemberClassCid(int $eid): string {
    return 'conreg:memberClasses:' . $eid . ':' . $this->languageManager
      ->getCurrentLanguage()
      ->getId();
  }

  /**
   * Return list of membership classes from config.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return object
   *   Object containing arrays class array and options array.
   */
  public function memberClasses(int $eid): object {
    $cid = $this->getMemberClassCid($eid);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $config = $this->loadConfig($eid);

    $memberClasses = (object) [
      'classes' => [],
      'options' => [],
    ];

    $classArray = $config->get('member.classes');
    // Build object variable from configuration array.
    foreach ($classArray as $classRef => $classVals) {
      $className = $classVals['name'] ?? $classRef;
      $memberClasses->classes[$classRef] = (object) [
        'name' => $className,
      ];
      $memberClasses->options[$classRef] = $className;
      foreach ($classVals as $category => $catVals) {
        if ($category != 'name') {
          $memberClasses->classes[$classRef]->$category = (object) [];
          foreach ($catVals as $entryName => $entryVal) {
            $memberClasses->classes[$classRef]->$category->$entryName = $entryVal;
          }
        }
      }
    }
    $this->cache->set($cid, $memberClasses);
    return $memberClasses;
  }

  /**
   * Function to save member classes to configuration.
   *
   * @param int $eid
   *   The event ID.
   * @param object $memberClasses
   *   Array of member classes to save.
   */
  public function saveMemberClasses(int $eid, object $memberClasses): void {
    $config = $this->configFactory->getEditable('conreg.settings.' . $eid);
    // Get existing classes and check they haven't been deleted.
    $classArray = $config->get('member.classes');
    foreach ($classArray as $classRef => $val) {
      if (!array_key_exists($classRef, $memberClasses->classes)) {
        // Member Class not in memberClasses, so delete from configuration.
        $config->clear("member.classes.$classRef");
      }
    }
    // Save all member classes to configuration.
    foreach ($memberClasses->classes as $classRef => $classVals) {
      $config->set("member.classes.$classRef.name", $classVals->name);
      foreach ($classVals as $category => $catVals) {
        if ($category != 'name') {
          foreach ($catVals as $entryName => $entryVal) {
            $config->set("member.classes.$classRef.$category.$entryName", $entryVal);
          }
        }
      }
    }
    $config->save();
    $this->cache->invalidate($this->getMemberClassCid($eid));
  }

  /**
   * Function to get cache ID for member types.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return string
   *   The cache identifier.
   */
  protected function getMemberTypeCid(int $eid): string {
    return 'conreg:memberTypes:' . $eid . ':' . $this->languageManager
      ->getCurrentLanguage()
      ->getId();
  }

  /**
   * Return list of membership types from config.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return object
   *   Object containing type details.
   */
  public function memberTypes(int $eid): object {
    $cid = $this->getMemberTypeCid($eid);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $config = $this->loadConfig($eid);
    $showRemaining = $config->get('payments.show_remaining') ?? FALSE;
    $days = $this->days($eid);

    // If we need to show remaining memberships, fetch number of members of each
    // type from database.
    $numberOfMembers = [];
    if ($showRemaining) {
      // Get the number of members by type.
      foreach ($this->memberStorage->adminMemberSummaryLoad($eid) as $entry) {
        $numberOfMembers[$entry['member_type']] = $entry['num'];
      }
    }

    $memberTypes = new \stdClass();
    $memberTypes->types = [];
    $memberTypes->allowDuplicates = [];
    $memberTypes->firstOptions = [];
    $memberTypes->publicOptions = [];
    $memberTypes->privateOptions = [];
    $memberTypes->publicNames = [];

    $typesArray = $config->get('member.types');
    foreach ($typesArray as $typeCode => $typeVals) {
      $type = new \stdClass();
      $soldOut = FALSE;
      foreach ($typeVals as $key => $val) {
        if ($key == 'days') {
          $type->days = [];
          $type->dayOptions = [];
          if (isset($val)) {
            foreach ($val as $dayCode => $dayVals) {
              $type->days[$dayCode] = (object) [
                'name' => $days[$dayCode],
                'description' => $dayVals['description'],
                'price' => $dayVals['price'],
              ];
              $type->dayOptions[$dayCode] = $dayVals['description'];
            }
          }
        }
        elseif ($key == 'confirmation') {
          $type->confirmation = (object) [
            'easy_email_type' => $val['easy_email_type'] ?? '',
          ];
        }
        elseif (!empty($key)) {
          $type->$key = $val;
        }
      }
      if (!isset($type->confirmation)) {
        $type->confirmation = (object) [
          'easy_email_type' => '',
        ];
      }
      // Display number of remaining memberships if required.
      if ($showRemaining && $typeVals['number_allowed']) {
        // If limited number for type, calculate number left.
        $numberForType = isset($numberOfMembers[$typeCode]) && $numberOfMembers[$typeCode] ? $numberOfMembers[$typeCode] : 0;
        $numberRemaining = $typeVals['number_allowed'] - $numberForType;
        $displayName = t('%name (%number remaining)', [
          '%name' => $type->name,
          '%number' => $numberRemaining,
        ]);
        if ($numberRemaining <= 0) {
          $soldOut = TRUE;
        }
        $type->remaining = $numberRemaining;
      }
      else {
        // No number limit so just show name.
        $displayName = $type->name;
      }
      $memberTypes->types[$typeCode] = $type;
      // If duplicates allowed for type, set in array for front end.
      $memberTypes->allowDuplicates[$typeCode] = $type->allowDuplicates ?? FALSE;
      if ($type->active && $type->allowFirst && !$soldOut) {
        $memberTypes->firstOptions[$typeCode] = $displayName;
      }
      if ($type->active && !$soldOut) {
        $memberTypes->publicOptions[$typeCode] = $displayName;
        $memberTypes->publicNames[$typeCode] = $type->name;
      }
      $memberTypes->privateOptions[$typeCode] = $displayName;
    }
    $tags = ['event:' . $eid . ':type'];
    if ($showRemaining) {
      $tags[] = 'event:' . $eid . ':remaining';
    }
    $this->cache->set($cid, $memberTypes, Cache::PERMANENT, $tags);
    return $memberTypes;
  }

  /**
   * Function to save member types to configuration.
   *
   * @param int $eid
   *   The event ID.
   * @param object $memberTypes
   *   Object containing the membership types.
   */
  public function saveMemberTypes(int $eid, object $memberTypes): void {
    $config = $this->configFactory->getEditable('conreg.settings.' . $eid);
    // Get existing types and check they haven't been deleted.
    $typeArray = $config->get('member.types');
    foreach ($typeArray as $typeRef => $val) {
      $config->clear("member.types.$typeRef");
    }
    // Save all member types to configuration.
    foreach ($memberTypes->types as $typeRef => $typeVals) {
      foreach ($typeVals as $key => $val) {
        if ($key == 'days' && isset($val)) {
          $config->clear("member.types.$typeRef.days");
          foreach ($val as $dayCode => $dayVals) {
            $config->set("member.types.$typeRef.days.$dayCode.description", $dayVals->description);
            $config->set("member.types.$typeRef.days.$dayCode.price", $dayVals->price);
          }
        }
        elseif ($key == 'dayOptions') {
          // Do nothing - day options are generated.
        }
        elseif ($key == 'confirmation') {
          $config->set("member.types.$typeRef.confirmation.easy_email_type", $val->easy_email_type);
        }
        elseif ($key == '') {
          // Don't save empty key.
        }
        else {
          $config->set("member.types.$typeRef.$key", $val);
        }
      }
    }
    $config->save();
    $this->cache->invalidate($this->getMemberTypeCid($eid));
  }

  /**
   * Return list of membership upgrade paths available from config.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return object
   *   Object containing upgrade options and upgrade details.
   */
  public function memberUpgrades(int $eid): object {
    // If member upgrades previously stored, just return them.
    if (!empty($this->memberUpgradesCache[$eid])) {
      return $this->memberUpgradesCache[$eid];
    }

    $config = $this->loadConfig($eid);

    $types = $this->memberTypes($eid);
    // One upgrade per line.
    $upgrades = $config->get('member_upgrades') ?? [];
    $upgradeOptions = [];
    $upgradeVals = [];
    foreach ($upgrades as $upgrade) {
      if (!empty($upgrade)) {
        [$upgradeId, $fromType, $fromDays, $toType, $todays, $toBadge, $desc, $price] = array_map('trim', array_pad(explode('|', $upgrade), 8, ''));
        // If list not present, add from type as first option.
        if (!isset($upgradeOptions[$fromType][$fromDays])) {
          $upgradeOptions[$fromType][$fromDays][0] = $types->types[$fromType]->name;
        }
        // Store.
        $upgradeOptions[$fromType][$fromDays][$upgradeId] = $desc;
        $upgradeVals[$upgradeId] = (object) [
          'fromType' => $fromType,
          'fromDays' => $fromDays,
          'toType' => $toType,
          'toDays' => $todays,
          'toBadgeType' => $toBadge,
          'desc' => $desc,
          'price' => $price,
        ];
      }
    }

    // Stash member upgrades in memoized array in case needed again.
    $this->memberUpgradesCache[$eid] = (object) [
      'options' => $upgradeOptions,
      'upgrades' => $upgradeVals,
    ];
    return $this->memberUpgradesCache[$eid];
  }

  /**
   * Return list of badge types from config.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return array
   *   List of badge types.
   */
  public function badgeTypes(int $eid): array {
    $config = $this->loadConfig($eid);
    // One type per line.
    $types = $config->get('badge_types') ?? [];
    $badgeTypes = [];
    foreach ($types as $type) {
      if (strlen(trim($type))) {
        [$code, $badgeType] = explode('|', $type);
        $badgeTypes[trim($code)] = trim($badgeType);
      }
    }
    return $badgeTypes;
  }

  /**
   * Return list of badge name options from config.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return array
   *   List of badge name options.
   */
  public function badgeNameOptions(int $eid): array {
    $config = $this->loadConfig($eid);
    // One type per line.
    $options = $config->get('badge_name_options') ?? [];
    $badgeNameOptions = [];
    foreach ($options as $option) {
      if (!empty($option)) {
        [$code, $badgeOption] = explode('|', $option);
        $badgeNameOptions[trim($code)] = trim($badgeOption);
      }
    }
    return $badgeNameOptions;
  }

  /**
   * If member name has been entered, show personal badge name options.
   *
   * @param int $eid
   *   The event ID.
   * @param string $firstName
   *   The member's first name.
   * @param string $lastName
   *   The member's last name.
   * @param int $maxLength
   *   The maximum length of the badge name.
   *
   * @return array
   *   A list of options for the badge name selector.
   */
  public function badgeNameOptionsForName(int $eid, string $firstName, string $lastName, int $maxLength): array {
    $badgeNameOptions = $this->badgeNameOptions($eid);
    if (!(empty($firstName) && empty($lastName))) {
      if (array_key_exists('F', $badgeNameOptions)) {
        $badgeNameOptions['F'] = substr($firstName, 0, $maxLength);
      }
      if (array_key_exists('N', $badgeNameOptions)) {
        $badgeNameOptions['N'] = substr($firstName . ' ' . $lastName, 0, $maxLength);
      }
      if (array_key_exists('L', $badgeNameOptions)) {
        $badgeNameOptions['L'] = substr($lastName . ', ' . $firstName, 0, $maxLength);
      }
    }
    return $badgeNameOptions;
  }

  /**
   * Return list of days from config.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return array
   *   Array of days.
   */
  public function days(int $eid): array {
    $config = $this->loadConfig($eid);
    // One type per line.
    $dayLines = $config->get('days') ?? [];
    $days = [];
    foreach ($dayLines as $dayLine) {
      if (trim($dayLine)) {
        [$dayCode, $dayName] = explode('|', $dayLine);
        $days[trim($dayCode)] = trim($dayName) ?? '';
      }
    }
    return $days;
  }

  /**
   * Return list of countries from config.
   *
   * @param int $eid
   *   The event ID.
   * @param bool $reset
   *   If true reset cached version.
   *
   * @return array
   *   List of countries.
   */
  public function memberCountries(int $eid, bool $reset = FALSE): array {
    $language = $this->languageManager->getCurrentLanguage()->getId();
    $cid = 'conreg:countryList_' . $eid . '_' . $language;
    // Check if previously used country list available.
    if (!$reset && $cache = $this->cache->get($cid)) {
      return $cache->data;
    }
    $config = $this->loadConfig($eid);
    // Get country list from country manager.
    $manager = new CountryManager($this->moduleHandler);
    $countries = $manager->getList();

    // Get no country label, if set.
    $noCountryLabel = trim($config->get('reference.no_country_label') ?? '');
    // Combine no country label with country list.
    $countryOptions = empty($noCountryLabel)
      ? $countries
      : array_merge([0 => $noCountryLabel], $countries);

    // Cache for future use.
    $this->cache->set($cid, $countryOptions);
    return $countryOptions;
  }

  /**
   * Return list of display options for membership list.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return array
   *   List of display options.
   */
  public function display(int $eid): array {
    // Get the config display options.
    $config = $this->loadConfig($eid);
    $options = $config->get('display_options.options') ?? [];
    $display_options = [];
    foreach ($options as $option) {
      [$code, $description] = array_pad(explode('|', trim($option)), 2, '');
      $display_options[$code] = $description;
    }
    return $display_options;
  }

  /**
   * Return the default display option for membership lists.
   *
   * @param int $eid
   *   The event ID.
   *
   * @return string
   *   The configured default display option.
   */
  public function displayDefault(int $eid): string {
    $config = $this->loadConfig($eid);

    return $config->get('display_options.default') ?: 'F';
  }

  /**
   * Return list of communications methods.
   *
   * @param int $eid
   *   The event ID.
   * @param bool $publicOnly
   *   If true, return only public methods.
   *
   * @return array
   *   List of communications methods.
   */
  public function communicationMethod(int $eid, bool $publicOnly = TRUE): array {
    $config = $this->loadConfig($eid);
    // One communications method per line.
    $methodOptions = [];
    $methods = $config->get('communications_method.options');
    if (!$methods) {
      return $methodOptions;
    }
    foreach ($methods as $method) {
      [$code, $description, $public] = array_pad(explode('|', trim($method)), 3, '');
      if (!$publicOnly || $public == '1' || $public == '') {
        $methodOptions[$code] = $description;
      }
    }
    return $methodOptions;
  }

  /**
   * Return list of payment methods.
   *
   * @return array
   *   List of communications methods.
   */
  public function paymentMethod(): array {
    return [
      'Stripe' => t('Stripe'),
      'Bank Transfer' => t('Bank Transfer'),
      'Cash' => t('Cash'),
      'Cheque' => t('Cheque'),
      'Credit Card' => t('Credit Card'),
      'Free' => t('Free'),
      'Groats' => t('Groats'),
      'PayPal' => t('PayPal'),
    ];
  }

  /**
   * Return yes and no.
   *
   * @return array
   *   List containing yes and no.
   */
  public function yesNo(): array {
    return [
      0 => t('No'),
      1 => t('Yes'),
    ];
  }

}
