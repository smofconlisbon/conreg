<?php

declare(strict_types=1);

namespace Drupal\conreg\Trait;

/**
 * Remembers a staff member's last-used printer for an event, per session.
 *
 * Session-scoped rather than a user setting: the same staff account is
 * often used across multiple reg-desk computers at once, each of which
 * may have a different physical printer attached, so each session
 * remembers its own choice independently. Shared between CheckInMembers
 * (which sets it when checking in and printing) and ReprintLabelForm
 * (which reads it as the default for reprinting a lost badge), so both
 * agree on the same printer without either hardcoding the other's
 * session key.
 *
 * Requires the using class to provide getRequest(), as any FormBase or
 * ControllerBase subclass does.
 */
trait PrinterSessionTrait {

  /**
   * Session key used to remember the selected printer for an event.
   */
  protected function printerSessionKey(int $eid): string {
    return 'conreg_checkin_printer_' . $eid;
  }

  /**
   * Gets the printer machine name remembered for this event, if any.
   */
  protected function getRememberedPrinter(int $eid): ?string {
    $request = $this->getRequest();
    if (!$request || !$request->hasSession()) {
      return NULL;
    }
    return $request->getSession()->get($this->printerSessionKey($eid));
  }

  /**
   * Remembers the selected printer in session for this event.
   */
  protected function rememberPrinter(int $eid, string $printerMachineName): void {
    $request = $this->getRequest();
    if ($request && $request->hasSession()) {
      $request->getSession()->set($this->printerSessionKey($eid), $printerMachineName);
    }
  }

}
