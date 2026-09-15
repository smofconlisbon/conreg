# Badge Label Printing

Opt-in per-event feature letting Member Check-In queue a badge label for
printing on a Dymo LabelWriter attached to a separate print-server device
(not the staff member's own workstation). The print-server agent
(`conreg_print_agent.py`) lives in a separate repository — see its
`README.md` and `PRINT_JOB_API.md` for the agent/hardware side; this page
covers the ConReg side.

**Rendering happens in ConReg, not the print agent.** Label content -
fonts, the auto-fit name splitting, the field position grid - is
rendered here by `Drupal\conreg\Service\LabelRenderer` (a port of the
print agent's original algorithm) and stored on the print job as a
base64 PNG. The agent only rotates that image to match the physical
media's feed orientation, applies "number of copies," and sends it to
CUPS - it never renders anything itself any more. This is what makes
an instant, no-agent-required label preview possible (see
[Preview](#preview)), at the cost of the print agent needing an update
in lockstep whenever this contract changes, since nothing here is
versioned/backward-compatible with older agent builds.

**Two interchangeable rendering backends**, behind `LabelCanvasInterface`
(`GdLabelCanvas`/`ImagickLabelCanvas`) - `LabelRenderer::createCanvas()`
picks Imagick when the PHP `imagick` extension is loaded, GD otherwise.
GD is always available (ships with PHP) but its text layout mis-decodes
4-byte UTF-8 sequences (mainly emoji) into several garbled glyphs no
matter which font is asked to draw them; Imagick decodes these
correctly. `LabelRenderer::getActiveBackendName()` reports which one
is in use, shown as a status line on the Label Printing Settings page.

**Font fallback for scripts DejaVu Sans Bold doesn't cover**, via
`SplitsFontRunsTrait` (shared by both canvas backends): text is split
into runs by Unicode block, each drawn/measured in whichever font
covers it, laid out left-to-right in sequence - so a name mixing
scripts (e.g. "Wang 王") renders correctly, not just whichever font
happens to be primary. CJK ideographs/kana/hangul route to
`NotoSansCJK-Bold.ttc` (system font, see Requirements); astral-plane
codepoints (almost entirely emoji) route to a bundled monochrome Noto
Emoji font (`assets/fonts/NotoEmoji-Regular.ttf`, SIL Open Font
License - bundled rather than a system dependency since no monochrome
emoji font is packaged in common distro repos, and the one that is
- Noto Color Emoji - is a color/bitmap format neither GD nor Imagick
can use for text rendering at all).

GD still can't decode astral-plane codepoints in the first place
(regardless of font), so `LabelRenderer::sanitizeText()` replaces them
with a plain "?" before layout runs - GD-only; skipped on Imagick,
which draws them via the emoji font instead. This is also why real
emoji glyphs only ever appear when Imagick is active: they're not a
font-coverage problem on GD, they're a decoding one.

**Known limitation: no right-to-left/shaped-script support.** Arabic,
Hebrew, and similar scripts render left-to-right with disconnected
letterforms on both backends - confirmed to be a limitation of the
underlying drawing APIs themselves (GD's `imagettftext()`, Imagick's
`ImagickDraw::annotation()`), not something this module's font-fallback
logic introduced or could reasonably work around; tested each API
completely bare, with no fallback logic involved, and got the identical
result. Correct rendering would need routing Imagick's text through
Pango (a real text-shaping/BIDI engine - confirmed available as a
compiled-in delegate on this build, and confirmed working for Arabic
via `Imagick::readImage('pango:...')` in testing), which has no GD
equivalent and would run in parallel with - not replace - the existing
GD/Imagick-direct rendering path, since GD would still need it. A
meaningfully larger, separate effort, deferred as low-priority pending
an actual need for RTL script support.

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
  agent's `--printer` argument — never shown to staff), plus `eid` (the
  event the printer belongs to) and `last_seen` (timestamp, updated by
  `PrintJobController::next()` on every successful poll — the poll
  itself is the heartbeat, whether or not a job was actually found).
  Has an admin UI (list, add, edit, delete) under the "Printers" tab on
  the Label Printing Settings page, which shows `last_seen` as a
  "N ago"/"Never" column so an admin can tell whether a given printer's
  agent is actually online — `PrinterForm` overrides the default `eid`
  widget with an event-name `<select>` built from
  `EventStorage::eventOptions()`.
- **`conreg_print_job`** — `eid`, `mid` (nullable — unset for test-print
  jobs, which have no real member), a *snapshot* of
  `member_name`/`member_number`/`days_attending`/`badge_type` taken at
  creation time (not a live join to `conreg_members`, so a job stays
  correct even if the member record changes before it's printed),
  `printer` (entity reference), `status` (`pending`/`claimed`, then
  whatever the agent reports back — conventionally `success`/`error`),
  `message`, `image_data` — a base64-encoded, **unrotated** PNG of
  the rendered label, produced by `LabelRenderer` once, at job-creation
  time, in `PrintJobManager::createJob()` (matching this entity's
  existing snapshot philosophy — rendering again on every poll/retry
  of the same job would be wasted work) — and `is_test` (boolean,
  default false; see [Test print](#test-print) below).
  `PrintJobController::next()` returns `image_data` verbatim as
  `image`; the print agent decodes it, rotates it per the configured
  label size's `rotate_degrees`, and prints it - it never calls into
  any rendering code.

There is also a **config** entity, `conreg_label_size`
(`Drupal\conreg\Entity\LabelSize`) — a named Dymo label size (width/
height in mm, plus a rotation override for printers that feed the
opposite way), selectable on the Label Printing Settings form. Global
like the settings form itself, since it describes physical label stock
rather than registration data. Ships with two defaults,
`standard_address` (28×89mm) and `large_address` (36×89mm); admins add
more via its own admin UI ("Label Sizes" tab) rather than a code
change. `LabelSize::getCupsPageSize()` computes the CUPS `PageSize`
keyword (`w{pt}h{pt}`, e.g. `w79h252`) from the stored mm dimensions —
the single source of truth for that conversion, so the print agent
doesn't duplicate it.

## Global label printing settings

Reachable from the top-level ConReg admin menu (`admin/config/conreg/label-printing`),
`LabelPrintingSettings` (`conreg_label_printing_settings` form,
config object `conreg.label_printing.settings`) is deliberately
**global, not per-event** — label size, copy count, and field layout
describe the physical print setup for a convention, not registration
data. It has three tabs: Settings, Label Sizes, and Printers.

Settings tab fields:

- **Label size** — a `conreg_label_size` entity, selected by machine
  name.
- **Number of copies to print** — e.g. `2` for a double-sided badge.
- **Suppress printing** — render and store labels but don't send them
  to the printer; mainly for testing.
- **Number of lines for name** — the print agent picks the largest
  font that fits the badge name across up to this many lines (was
  hardcoded to 2).
- **Field positions** — one `<select>` per positionable field (Badge
  name, Member Number, Days attending, Badge type), each choosing one
  of 8 slots: `top_left`/`top_center`/`top_right`/`middle`/
  `bottom_left`/`bottom_center`/`bottom_right`/`none` ("don't print").
  `validateForm()` rejects two fields sharing the same non-`none`
  position.
- **Delete print jobs after (days)** — `retention_days`, default `30`.
  See [Retention cron](#retention-cron) below.

Label size, name lines, and field positions never leave ConReg - they
only feed `LabelRenderer`. The print agent fetches only the print-time
settings it still needs (page size, rotation, copies, suppress
printing) from a slimmed-down endpoint — see
[Settings endpoint](#settings-endpoint) below.

## Preview

The Settings tab has a "Preview" button and an `<img>` that render a
sample label with no print agent involved at all - a plain
`fetch()`-based JS library (`conreg_label_preview`, deliberately not
Drupal's `#ajax`, since it only ever needs to update one image) reads
the form's *current, unsaved* field values and posts them to
`LabelPreviewController::preview()`
(`POST admin/config/conreg/label-printing/preview`, permission
`configure convention registration` — session-authenticated, not the
print agent's API-key auth). It calls the same `LabelRenderer::render()`
as a real print job and returns a `data:image/png;base64,...` URI ready
to drop straight into the `<img>`'s `src`. Any field omitted from the
POST body falls back to the currently *saved* settings, so posting an
empty body previews "as saved."

The controller takes arbitrary field values rather than assuming "the
saved settings" specifically so a future check-in-screen "preview this
member's badge" button can reuse it directly, without new plumbing.

## Test print

Next to Preview, a printer `<select>` (every `conreg_printer` across
all events, labelled "Name (Event)" via `EventStorage::eventOptions()`)
and a "Test print" button let an admin actually send a label to a real
printer without a real member/check-in involved. The button click
handler in `conreg_label_preview.js` re-runs the preview first (so it's
always sending what the page currently shows, not a stale earlier
render), then POSTs that exact `image_data_uri` plus the chosen printer
ID to `LabelPreviewController::testPrint()`
(`POST admin/config/conreg/label-printing/test-print`, same
session-authenticated permission as Preview).

`testPrint()` does **not** call `LabelRenderer` again — it hands the
already-rendered bytes straight to
`PrintJobManager::createTestJob()`, which resolves the printer's `eid`
from the `Printer` entity and creates a normal `conreg_print_job` row
(`status: pending`, `mid` left unset, `is_test: true`) with those exact
bytes as `image_data`. From there it's indistinguishable from a real
check-in job to `PrintJobController::next()`/the print agent — the next
poll of that printer rotates and prints it like any other job. This
also means, unavoidably, a test print is **not instant**: ConReg has no
way to reach a print-server device directly (they're outbound-only
pollers by design), so it's bounded by however often that printer's
agent polls.

If no printers exist yet, the select/button are disabled with a link to
the Printers tab instead of silently doing nothing.

## Retention cron

`CronHooks::cron()` (`#[Hook('cron')]`, in `src/Hook/CronHooks.php`) deletes `conreg_print_job`
entities whose `changed` is older than `retention_days` (default 30),
in batches of 50, regardless of status - now that every job carries a
rendered image, letting old rows accumulate indefinitely would bloat
the database noticeably faster than before this feature. A job that's
sat `pending` for weeks isn't going to print, so there's no
status-based exemption.

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

### Settings endpoint

`GET /api/print-jobs/{eid}/settings` → `PrintJobController::settings()`
returns only what's still a print-time concern for the agent now that
rendering has moved into ConReg: CUPS page size, rotation degrees,
copies, and suppress_printing. Reuses the same `isAuthorized()`
per-event shared key as `next`/`result` — the settings content is
global, but gating stays event-scoped since that's the auth model
agents already have. Full response shape is documented in the
print-agent repo's `PRINT_JOB_API.md`.

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
