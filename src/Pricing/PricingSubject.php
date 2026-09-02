<?php

declare(strict_types=1);

namespace Drupal\conreg\Pricing;

use Drupal\conreg\Member;

/**
 * A member to be priced, however that data was sourced.
 *
 * Pricing plugins only ever see a PricingSubject - they never know whether
 * it was built from an in-progress registration form or a persisted member
 * row, which is what makes it possible to run the same pricing logic both
 * at registration time and when recomputing prices at payment time.
 */
final readonly class PricingSubject {

  /**
   * Constructs a PricingSubject.
   *
   * @param int $memberNo
   *   The member's position on the registration/payment.
   * @param string $memberType
   *   The member type code.
   * @param array $selectedDayCodes
   *   Day codes selected on the form (or persisted), including the
   *   type's own code if the "whole weekend" checkbox was ticked.
   * @param \Drupal\conreg\Pricing\AddOnSelection[] $addOns
   *   This member's own (non-global) add-on selections, keyed by add-on ID.
   * @param int|null $mid
   *   The persisted member ID, or NULL if not yet saved.
   */
  public function __construct(
    public int $memberNo,
    public string $memberType,
    public array $selectedDayCodes,
    public array $addOns,
    public ?int $mid = NULL,
  ) {}

  /**
   * Builds a subject from registration-form values.
   *
   * @param int $memberNo
   *   The member's position on the form.
   * @param array $form_values
   *   The full form values array.
   * @param string|null $defaultType
   *   The event's configured default member type.
   */
  public static function fromFormValues(int $memberNo, array $form_values, ?string $defaultType = NULL): self {
    $memberValues = $form_values['members']['member' . $memberNo] ?? [];
    $memberType = $memberValues['type'] ?? $defaultType ?? '';

    $selectedDayCodes = [];
    foreach ($memberValues['dayOptions']['days'] ?? [] as $dayCode => $checked) {
      if ($checked) {
        $selectedDayCodes[] = $dayCode;
      }
    }

    $addOns = [];
    foreach ($memberValues['add_on'] ?? [] as $addOnId => $addOnVals) {
      if (!empty($addOnVals['option'])) {
        $addOns[$addOnId] = new AddOnSelection($addOnId, $addOnVals['option'], 0.0, FALSE);
      }
      elseif (!empty($addOnVals['free_amount'])) {
        $addOns[$addOnId] = new AddOnSelection($addOnId, NULL, (float) $addOnVals['free_amount'], FALSE);
      }
    }

    return new self($memberNo, $memberType, $selectedDayCodes, $addOns);
  }

  /**
   * Builds a subject for the admin "add member at check-in" form.
   *
   * Check-in's `$form_values['unpaid']['add']['days']` is a flat map of
   * day code => checked, unlike the registration form's nested
   * `dayOptions.days` structure, and has no "whole weekend" checkbox or
   * add-ons at all.
   *
   * @param array $form_values
   *   The check-in form values.
   */
  public static function fromCheckInFormValues(array $form_values): self {
    $addValues = $form_values['unpaid']['add'] ?? [];
    $memberType = $addValues['memberType'] ?? '';

    $selectedDayCodes = [];
    foreach ($addValues['days'] ?? [] as $dayCode => $checked) {
      if ($checked) {
        $selectedDayCodes[] = $dayCode;
      }
    }

    return new self(1, $memberType, $selectedDayCodes, []);
  }

  /**
   * Builds a subject from a persisted member row plus their add-on rows.
   *
   * @param \Drupal\conreg\Member $member
   *   The persisted member.
   * @param array $addonRows
   *   This member's rows from `conreg_member_addons` (via
   *   `AddonStorage::loadAll(['mid' => $member->mid])`), excluding any rows
   *   that belong to a global add-on (the caller is responsible for
   *   separating those out into the shared `PricingContext`, since a
   *   global add-on isn't owned by any one member).
   * @param int $memberNo
   *   A stable per-payment sequence number - only used for tie-breaking
   *   and display, never as a database key.
   */
  public static function fromPersistedMember(Member $member, array $addonRows, int $memberNo): self {
    $selectedDayCodes = array_filter(explode('|', (string) ($member->days ?? '')), fn($code) => $code !== '');

    $addOns = [];
    foreach ($addonRows as $row) {
      $addOnId = $row['addon_name'];
      if (!empty($row['addon_option'])) {
        $addOns[$addOnId] = new AddOnSelection($addOnId, $row['addon_option'], 0.0, FALSE);
      }
      else {
        $addOns[$addOnId] = new AddOnSelection($addOnId, NULL, (float) $row['addon_amount'], FALSE);
      }
    }

    return new self($memberNo, (string) $member->member_type, array_values($selectedDayCodes), $addOns, (int) $member->mid);
  }

}
