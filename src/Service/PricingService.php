<?php

declare(strict_types=1);

namespace Drupal\conreg\Service;

use Drupal\conreg\Addons;
use Drupal\conreg\ConregConfig;
use Drupal\conreg\ConregOptions;
use Drupal\conreg\Member;
use Drupal\conreg\Payment;
use Drupal\conreg\Pricing\MemberPriceResult;
use Drupal\conreg\Pricing\MemberPricingRulePluginManager;
use Drupal\conreg\Pricing\PriceLine;
use Drupal\conreg\Pricing\PricingAdjustmentPluginManager;
use Drupal\conreg\Pricing\PricingContext;
use Drupal\conreg\Pricing\PricingRecomputeResult;
use Drupal\conreg\Pricing\PricingResult;
use Drupal\conreg\Pricing\PricingSubject;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Calculates registration pricing via the pricing plugin architecture.
 */
final class PricingService implements PricingServiceInterface {

  /**
   * The float-comparison tolerance used to detect price drift.
   */
  private const DRIFT_TOLERANCE = 0.001;

  /**
   * Constructs a PricingService.
   */
  public function __construct(
    protected MemberPricingRulePluginManager $memberRuleManager,
    protected PricingAdjustmentPluginManager $adjustmentManager,
    #[Autowire(service: 'logger.channel.conreg')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function priceRegistration(PricingContext $context, array $subjects): PricingResult {
    $memberRules = $this->memberRuleManager->getSortedInstances();

    $memberResults = [];
    foreach ($subjects as $subject) {
      $lines = [];
      $days = '';
      $daysDesc = '';
      foreach ($memberRules as $rule) {
        $contribution = $rule->priceMember($subject, $context);
        $lines = [...$lines, ...$contribution->lines];
        if ($contribution->days !== NULL) {
          $days = $contribution->days;
        }
        if ($contribution->daysDesc !== NULL) {
          $daysDesc = $contribution->daysDesc;
        }
      }
      $memberResults[$subject->memberNo] = new MemberPriceResult(
        $subject->memberNo,
        $subject->mid,
        $subject->memberType,
        $days,
        $daysDesc,
        $lines,
      );
    }

    foreach ($this->adjustmentManager->getSortedInstances() as $adjustment) {
      foreach ($adjustment->adjust($memberResults, $context) as $priceAdjustment) {
        $target = $memberResults[$priceAdjustment->targetMemberNo] ?? NULL;
        if ($target !== NULL) {
          $memberResults[$priceAdjustment->targetMemberNo] = $target->withAdjustments([$priceAdjustment]);
        }
      }
    }

    return new PricingResult($memberResults, $this->globalAddOnLines($context));
  }

  /**
   * {@inheritdoc}
   */
  public function recomputeForPayment(Payment $payment): PricingRecomputeResult {
    if (!empty($payment->paidDate)) {
      return new PricingRecomputeResult($payment, FALSE);
    }

    $memberLines = array_values(array_filter($payment->paymentLines, fn($line) => $line->type === 'member' && !empty($line->mid)));
    if (!$memberLines) {
      return new PricingRecomputeResult($payment, FALSE);
    }

    $members = [];
    foreach ($memberLines as $line) {
      if (!isset($members[$line->mid])) {
        $members[$line->mid] = Member::loadMember((int) $line->mid);
      }
    }

    $eid = (int) reset($members)->eid;
    $config = ConregConfig::getConfig($eid);
    $types = ConregOptions::memberTypes($eid, $config)->types;

    $context = new PricingContext(
      $eid,
      $config,
      $types,
      (string) $config->get('payments.symbol'),
      (bool) $config->get('discount.enable'),
      (int) $config->get('discount.free_every'),
    );

    // Add-on line amounts are deliberately not reconciled here (see the
    // interface docblock), so subjects carry no add-on selections - only
    // the base type/day price (used below) is recomputed. A member whose
    // type can no longer be resolved is excluded from the group entirely
    // (fail open): their payment line is left untouched below, and the
    // nth-member-free discount is computed only among the members that
    // could be priced.
    $subjects = [];
    $memberNoByMid = [];
    $memberNo = 0;
    foreach ($members as $mid => $member) {
      $memberNo++;
      $memberNoByMid[$mid] = $memberNo;
      $subject = PricingSubject::fromPersistedMember($member, [], $memberNo);
      if (!$context->hasType($subject->memberType)) {
        $this->logger->error('Pricing recompute could not price member @mid: unknown member type "@type".', [
          '@mid' => $mid,
          '@type' => $subject->memberType,
        ]);
        continue;
      }
      $subjects[$memberNo] = $subject;
    }

    $result = $this->priceRegistration($context, $subjects);

    $drifted = FALSE;
    foreach ($memberLines as $line) {
      $memberNo = $memberNoByMid[$line->mid] ?? NULL;
      $memberResult = $memberNo !== NULL ? ($result->memberResults[$memberNo] ?? NULL) : NULL;
      if ($memberResult === NULL) {
        // This member's price couldn't be recomputed - leave their line.
        continue;
      }

      $newAmount = $memberResult->basePriceMinusFree();
      if (abs(((float) $line->amount) - $newAmount) > self::DRIFT_TOLERANCE) {
        $this->logger->warning('Pricing drift detected for member @mid on payment @payid: @old -> @new.', [
          '@mid' => $line->mid,
          '@payid' => $payment->payId,
          '@old' => $line->amount,
          '@new' => $newAmount,
        ]);
        $line->amount = $newAmount;
        $line->save($payment->payId);
        $drifted = TRUE;

        $member = $members[$line->mid];
        $member->member_price = $memberResult->basePrice();
        $member->member_total = $memberResult->price();
        $member->saveMember();
      }
    }

    if ($drifted) {
      $payment->paymentAmount = array_reduce($payment->paymentLines, fn($carry, $line) => $carry + (float) $line->amount, 0.0);
      $payment->save();
    }

    return new PricingRecomputeResult($payment, $drifted);
  }

  /**
   * Builds the sitewide (non-per-member) global add-on price lines.
   *
   * Global add-ons apply once per registration rather than per member, so
   * they don't fit either plugin interface - they're computed here rather
   * than forced into a plugin shape.
   *
   * @return \Drupal\conreg\Pricing\PriceLine[]
   *   The global add-on price lines.
   */
  private function globalAddOnLines(PricingContext $context): array {
    $lines = [];
    $addOns = $context->config->get('add-ons') ?? [];
    /** @var \Drupal\conreg\Pricing\AddOnSelection $selection */
    foreach ($context->globalAddOns as $addOnId => $selection) {
      $addOnVals = $addOns[$addOnId] ?? [];
      $addon = $addOnVals['addon'] ?? [];
      $label = Addons::getAddOnLabel($addOnId, $addOnVals);
      if (!empty($addon['free'])) {
        if ($selection->freeAmount > 0) {
          $lines[] = new PriceLine('global_addon:' . $addOnId, 'addon', $selection->freeAmount, $label, excludeFromMinusFree: TRUE);
        }
      }
      elseif ($selection->option !== NULL) {
        [, $addOnPrices] = Addons::memberAddons($addon['options'] ?? '');
        $lines[] = new PriceLine('global_addon:' . $addOnId, 'addon', (float) ($addOnPrices[$selection->option] ?? 0), $label);
      }
    }
    return $lines;
  }

}
