# Badge Label Printing

Opt-in per-event feature letting Member Check-In queue a badge label for
printing on a Dymo LabelWriter attached to a separate print-server device
(not the staff member's own workstation). The print-server agent
(`conreg_print_agent.py`) lives in a separate repository — see its
`README.md` and `PRINT_JOB_API.md` for the agent/hardware side; this page
covers the ConReg side.

## Enabling it

Event Configuration's Check In Page section has two relevant fields:

- **Enable badge label printing** (`checkin.label_printing_enabled`,
  boolean, default off) — until checked, Member Check-In shows only its
  original "Check-in Selected" button; no printer UI, no print jobs.
- **Print Job API Key** (`checkin.print_api_key`) — see
  [Credentials via the Key module](#credentials-via-the-key-module)
  below. Required before any print-server agent can poll this event.

## Entities

Two content entities — `Printer` and `PrintJob`
(`Drupal\conreg\Entity\Printer`/`PrintJob`) — the first *content* entities
anywhere in this codebase (`conreg_subscription_rule`, described in
`architecture/data-model.md`, is a config entity; these have real base
tables). New storage in this codebase should follow this pattern rather
than adding `hook_schema()` tables.

- **`conreg_printer`** — `name` (display label shown to staff, e.g.
  "Bilbo Baggins") and `machine_name` (CUPS-safe identifier, e.g.
  `bilbo_baggins`, sent over the API and matched against the print
  agent's `--printer` argument — never shown to staff). No admin UI yet;
  created via `drush php:eval`.
- **`conreg_print_job`** — `eid`, `mid`, a *snapshot* of
  `member_name`/`member_number`/`days_attending` taken at creation time
  (not a live join to `conreg_members`, so a job stays correct even if
  the member record changes before it's printed), `printer` (entity
  reference), `status` (`pending`/`claimed`, then whatever the agent
  reports back — conventionally `success`/`error`), `message`.

## Credentials via the Key module

`checkin.print_api_key` stores a **Key entity ID**, the same pattern used
for Stripe credentials (see `site-building/payments.md`) —
`PrintJobController::resolvePrintApiKey()` resolves it to an actual value
via `KeyRepositoryInterface::getKey($keyId)?->getKeyValue()`, mirroring
`StripeService::resolveKey()`.

Unlike Stripe's two-key pair, this is a single **shared secret per
event**: every print-server agent for that event's convention sends the
same value as an `Authorization: Bearer <key>` header on every request.
`PrintJobController::isAuthorized()` checks it with `hash_equals()`
(timing-safe comparison) before either API method does anything else.

**There is no open fallback.** If an event has no key configured,
`resolvePrintApiKey()` returns an empty string and every request to that
event's print-job endpoints is refused with `401` — unlike a
misconfigured Stripe key (which fails only when a payment is attempted),
an unset print API key fails closed immediately, since the alternative
would be silently exposing live attendee data (member names, badge
numbers, days attending) to an unauthenticated caller.

`EventConfig`'s Check In Page section uses `#type => 'key_select'`
(filtered to `type_group: 'authentication'` keys) for this field, same
widget as the Stripe key fields.

## Print job lifecycle

`PrintJobController::next()` atomically claims the oldest eligible job
for an event/printer pair — `claimNextJob()`/`eligibleForClaimCondition()`
use a `SELECT` then a conditionally-matching `UPDATE` inside a
transaction, so two agents polling the same printer can't both receive
the same job. A job stays `claimed` for up to
`STALE_CLAIM_TIMEOUT_SECONDS` (300s); past that with no result posted
(e.g. the agent crashed or lost connectivity), it becomes eligible again
automatically on the next poll — a job is never stuck forever with
nothing else happening to it.

The full HTTP contract (`GET .../next`, `POST .../result`, status codes,
JSON shapes) is documented in the print-agent repo's `PRINT_JOB_API.md`,
not duplicated here, since that file is also the reference the
agent-side code is written against.

## Member Check-In UI

When `checkin.label_printing_enabled` is on, `CheckInMembers` adds a
printer `<select>` and a second submit button, "Check In and Print
Labels", alongside the existing "Check-in Selected":

- `validatePrinterSelected()` blocks submission with a form error if no
  printer is chosen — printing never silently gets skipped.
- The confirm screen (`buildConfirmForm()`) names the chosen printer;
  `confirmCheckInSubmit()` then checks the member in **and** calls
  `PrintJobManager::createJob()` for them, with its own per-member
  try/catch so one bad printer selection doesn't abort check-in for the
  rest of the batch.
- The last-chosen printer is remembered **per browser session** (via
  `$this->getRequest()->getSession()`, not a Drupal user setting) —
  deliberately session-scoped rather than per-account, since the same
  staff login is often active on multiple reg-desk computers at once,
  each potentially needing a different printer remembered independently.

No new Drupal permission gates this — it rides on the existing
`check in convention members` permission (see `core/permissions.md`);
access to the HTTP API itself is controlled entirely by the shared key
above, not by Drupal permissions.

## Reprinting / undo

Not yet implemented — see the ConReg issue queue for the follow-up
covering undo-check-in and reprint-label actions (proposed behind a
separate, more privileged permission than plain check-in).
