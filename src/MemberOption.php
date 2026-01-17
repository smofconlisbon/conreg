<?php

namespace Drupal\conreg;

/**
 * Store a member's options.
 */
class MemberOption {

  /**
   * Member ID.
   *
   * @var int
   */
  public $mid;

  /**
   * Option ID.
   *
   * @var int
   */
  public $optionId;

  /**
   * Whether the option is selected (1 or 0).
   *
   * @var bool|int
   */
  public $isSelected;

  /**
   * Option detail text.
   *
   * @var string|null
   */
  public $optionDetail;

  /**
   * Constructs a new MemberOption object.
   *
   * @param int $mid
   *   Member ID.
   * @param int $optionId
   *   Option ID.
   * @param bool|int $isSelected
   *   Whether the option is selected.
   * @param string|null $optionDetail
   *   Option detail text.
   */
  public function __construct($mid, $optionId, $isSelected, $optionDetail) {
    $this->mid = $mid;
    $this->optionId = $optionId;
    $this->isSelected = $isSelected;
    $this->optionDetail = $optionDetail;
  }

  /**
   * Save member option.
   */
  public function saveMemberOption() {
    FieldOptionStorage::upsertMemberOption([
      'mid' => $this->mid,
      'optid' => $this->optionId,
      'is_selected' => $this->isSelected,
      'option_detail' => $this->optionDetail,
    ]);
  }

  /**
   * Load a single member option.
   *
   * @param int $mid
   *   Member ID.
   * @param int $optid
   *   Option ID.
   *
   * @return \Drupal\conreg\MemberOption|null
   *   MemberOption object or NULL if not found.
   */
  public static function loadMemberOption($mid, $optid) {
    if ($option = FieldOptionStorage::getMemberOptions($mid, $optid, selected: FALSE)) {
      return new MemberOption($mid, $optid, $option['is_selected'], $option['option_detail']);
    }
    return NULL;
  }

  /**
   * Load all options for a member.
   *
   * @param int $mid
   *   Member ID.
   *
   * @return \Drupal\conreg\MemberOption[]
   *   Array of MemberOption objects keyed by option ID.
   */
  public static function loadAllMemberOptions($mid) {
    $memberOptions = [];
    foreach (FieldOptionStorage::getMemberOptions($mid, selected: FALSE) as $opt) {
      $memberOptions[$opt['optid']] = new MemberOption($opt['mid'], $opt['optid'], $opt['is_selected'], $opt['option_detail']);
    }
    return $memberOptions;
  }

}
