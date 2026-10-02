<?php

declare(strict_types=1);

namespace Drupal\conreg;

use Drupal\conreg\Service\ConregOptions;
use Drupal\conreg\Service\EventStorage;
use Drupal\conreg\Service\RatePlanManager;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared base for the lists of an event's planned and applied rate plans.
 *
 * Rate plan pages sit under their event's configuration, so the lists show
 * only the plans of the event in the current route's eid parameter.
 */
abstract class RatePlanListBuilderBase extends EntityListBuilder {

  /**
   * The rate plan storage.
   *
   * @var \Drupal\conreg\RatePlanStorage
   */
  protected $storage;

  /**
   * The ID of the event whose plans are listed.
   */
  protected int $eid;

  /**
   * The event's member types, loaded when first needed.
   */
  protected ?object $memberTypes = NULL;

  /**
   * Constructs a RatePlanListBuilderBase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The rate plan entity type.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match, whose eid parameter is the event to list.
   * @param \Drupal\conreg\Service\ConregOptions $conregOptions
   *   The ConReg options service.
   * @param \Drupal\conreg\Service\EventStorage $eventStorage
   *   The event storage service.
   * @param \Drupal\conreg\Service\RatePlanManager $ratePlanManager
   *   The rate plan manager.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entityTypeManager,
    RouteMatchInterface $routeMatch,
    protected ConregOptions $conregOptions,
    protected EventStorage $eventStorage,
    protected RatePlanManager $ratePlanManager,
  ) {
    parent::__construct($entity_type, $entityTypeManager->getStorage($entity_type->id()));
    $this->eid = (int) $routeMatch->getRawParameter('eid');
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get(ConregOptions::class),
      $container->get(EventStorage::class),
      $container->get(RatePlanManager::class),
    );
  }

  /**
   * The page title.
   *
   * @param string $eventName
   *   The name of the event whose plans are listed.
   */
  abstract protected function pageTitle(string $eventName): TranslatableMarkup;

  /**
   * The text shown when there are no plans to list.
   */
  abstract protected function emptyText(): TranslatableMarkup;

  /**
   * The event's member types, as returned by ConregOptions::memberTypes().
   */
  protected function memberTypes(): object {
    return $this->memberTypes ??= $this->conregOptions->memberTypes($this->eid);
  }

  /**
   * Build the list of a plan's prices, showing how each one changes.
   *
   * Each member type's day prices are listed after its main price, labelled
   * e.g. "Dealer, Friday".
   *
   * @param array $from
   *   The main prices before the plan, keyed by member type code. NULL for
   *   member types that had no price.
   * @param array $to
   *   The plan's main prices, keyed by member type code, in the order to list
   *   them. NULL for member types the plan has no price for.
   * @param array $dayFrom
   *   The day prices before the plan, keyed by member type code then day code.
   *   A day with no price is free, so is given as 0.
   * @param array $dayTo
   *   The plan's day prices, keyed by member type code then day code, in the
   *   order to list them. NULL for days the plan has no price for. Days of
   *   member types not in $to aren't listed.
   * @param string[] $names
   *   The member type names, keyed by member type code. Member types with no
   *   name are listed by their code.
   * @param string[][] $dayNames
   *   The day names, keyed by member type code then day code. Days with no
   *   name are listed by their code.
   * @param bool $hideUnchanged
   *   Whether to leave out prices the plan doesn't change, so lists stay short
   *   however many member types there are.
   *
   * @return array
   *   An item list render array.
   */
  protected function buildPriceChanges(array $from, array $to, array $dayFrom, array $dayTo, array $names, array $dayNames, bool $hideUnchanged): array {
    $changes = [];
    foreach ($to as $type => $price) {
      $name = $names[$type] ?? (string) $type;
      $changes[] = [$name, $from[$type] ?? NULL, $price];
      foreach ($dayTo[$type] ?? [] as $day => $dayPrice) {
        $changes[] = [
          $this->ratePlanManager->dayLabel($name, $dayNames[$type][$day] ?? (string) $day),
          $dayFrom[$type][$day] ?? NULL,
          $dayPrice,
        ];
      }
    }

    $items = [];
    foreach ($changes as [$name, $before, $price]) {
      if ($price === NULL) {
        $items[] = [
          '#markup' => $this->t('@type: @price', [
            '@type' => $name,
            '@price' => $this->ratePlanManager->formatPrice($this->eid, $price),
          ]),
          '#wrapper_attributes' => ['class' => ['conreg-rate-plan__missing']],
        ];
        continue;
      }

      if ($this->ratePlanManager->pricesEqual($before, $price)) {
        if (!$hideUnchanged) {
          $items[] = [
            '#markup' => $this->t('@type: @to (unchanged)', [
              '@type' => $name,
              '@to' => $this->ratePlanManager->formatPrice($this->eid, $price),
            ]),
            '#wrapper_attributes' => ['class' => ['conreg-rate-plan__unchanged']],
          ];
        }
        continue;
      }

      $args = [
        '@type' => $name,
        '@from' => $this->ratePlanManager->formatPrice($this->eid, $before),
        '@to' => $this->ratePlanManager->formatPrice($this->eid, $price),
        '@change' => $this->ratePlanManager->formatPriceChange($this->eid, $before, $price),
      ];
      $item = [
        // There's no difference to show if the member type had no price.
        '#markup' => $args['@change'] === ''
          ? $this->t('@type: @from → @to', $args)
          : $this->t('@type: @from → @to (@change)', $args),
      ];
      // Changed prices only need emphasis when listed among unchanged ones.
      if (!$hideUnchanged) {
        $item['#wrapper_attributes']['class'][] = 'conreg-rate-plan__changed';
      }
      $items[] = $item;
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#empty' => $this->t('No price changes'),
      '#attributes' => ['class' => ['conreg-rate-plan__price-list']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $event = $this->eventStorage->load(['eid' => $this->eid]);
    if (!$event) {
      throw new NotFoundHttpException();
    }

    $build = parent::render();
    $build['#title'] = $this->pageTitle($event['event_name']);
    // Core's default, "There are no rate plans yet.", is misleading when the
    // event has plans in the other list.
    $build['table']['#empty'] = $this->emptyText();
    $build['#attached']['library'][] = 'conreg/conreg_rate_plans';
    // Planned dates are described relative to today, so the list can't be
    // cached.
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
