<?php

namespace Drupal\conreg;

/**
 * Store a member's options.
 */
class MemberOption {
  public $mid;
  public $optionId;
  public $isSelected;
  public $optionDetail;

  /**
   * Constructs a new Member object.
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
   * Load specific member option (not yet implemented).
   */
  public static function loadMemberOption($mid, $optid) {
  }

  /**
   * Load all options for specified member.
   */
  public static function loadAllMemberOptions($mid) {
    $memberOptions = [];
    foreach (FieldOptionStorage::getMemberOptions($mid, FALSE) as $opt) {
      $memberOptions[$opt['optid']] = new MemberOption($opt['mid'], $opt['optid'], $opt['is_selected'], $opt['option_detail']);
    }
    return $memberOptions;
  }

}
