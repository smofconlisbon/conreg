<?php

namespace Drupal\conreg;

/**
 * Provides a value object for storing payment line data.
 */
class PaymentLine {

  /**
   * Payment ID.
   *
   * @var int|null
   */
  public $payId;

  /**
   * Payment line ID.
   *
   * @var int|null
   */
  public $payLineId;

  /**
   * Member ID.
   *
   * @var int|null
   */
  public $mid;

  /**
   * Payment type.
   *
   * @var string|null
   */
  public $type;

  /**
   * Payment line description.
   *
   * @var string|null
   */
  public $lineDesc;

  /**
   * Payment amount.
   *
   * @var float|null
   */
  public $amount;

  /**
   * Constructs a new PaymentLine object.
   *
   * @param int|null $mid
   *   Member ID.
   * @param string|null $type
   *   Payment type.
   * @param string|null $lineDesc
   *   Payment line description.
   * @param float|null $amount
   *   Payment amount.
   */
  public function __construct($mid = NULL, $type = NULL, $lineDesc = NULL, $amount = NULL) {
    $this->mid = $mid;
    $this->type = $type;
    $this->lineDesc = $lineDesc;
    $this->amount = $amount;
  }

  /**
   * Saves the payment line.
   *
   * @param int|null $payId
   *   Payment ID.
   *
   * @return int
   *   The payment line ID.
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
   * Loads a payment line by line ID.
   *
   * @param int $lineId
   *   Payment line ID.
   *
   * @return static|null
   *   The loaded payment line, or NULL if not found.
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
   * Loads all payment lines for a payment.
   *
   * @param int $payId
   *   Payment ID.
   *
   * @return static[]|null
   *   Array of payment lines, or NULL if none found.
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
