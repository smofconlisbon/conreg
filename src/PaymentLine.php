<?php

namespace Drupal\conreg;

/**
 * Class to help with storage of payment lines.
 */
class PaymentLine {
  public $payId;
  public $payLineId;
  public $mid;
  public $type;
  public $lineDesc;
  public $amount;

  /**
   * Construct upgrade manager. Store event ID and set up array.
   */
  public function __construct($mid = NULL, $type = NULL, $lineDesc = NULL, $amount = NULL) {
    $this->mid = $mid;
    $this->type = $type;
    $this->lineDesc = $lineDesc;
    $this->amount = $amount;
  }

  /**
   * Save the payment line.
   */
  public function save($payId = NULL) {
    $payLine = [
      'payid' => $payId,
      'mid' => $this->mid,
      'payment_type' => $this->type,
      'line_desc' => $this->lineDesc,
      'amount' => $this->amount,
    ];
    // If we have a Line ID, we are updating an existing payment line.
    if (isset($this->payLineId)) {
      $payLine['lineid'] = $this->payLineId;
      PaymentStorage::updateLine($payLine);
      return $this->payLineId;
    }
    // No Line ID, so inserting a new line.
    else {
      $this->payLineId = PaymentStorage::insertLine($payLine);
      return $this->payLineId;
    }
  }

  /**
   * Load the line by payment line ID.
   */
  public static function load($lineId) {
    if ($payLine = PaymentStorage::loadLine(['lineid' => $lineId])) {
      $line = new self($payLine['mid'], $payLine['payment_type'], $payLine['line_desc'], $payLine['amount']);
      $line->payId = $payLine['payid'];
      $line->payLineId = $lineId;
      return $line;
    }
    else {
      return NULL;
    }
  }

  /**
   * Load multiple lines.
   */
  public static function loadLines($payId) {
    $lines = [];
    if ($payLines = PaymentStorage::loadAllLines(['payid' => $payId])) {
      foreach ($payLines as $payLine) {
        $line = new PaymentLine($payLine['mid'], $payLine['payment_type'], $payLine['line_desc'], $payLine['amount']);
        $line->payId = $payLine['payid'];
        $line->payLineId = $payLine['lineid'];
        $lines[] = $line;
      }
      return $lines;
    }
    else {
      return NULL;
    }
  }

}
