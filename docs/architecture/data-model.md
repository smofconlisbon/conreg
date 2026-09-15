# Data Model

## Core tables

| Table | Purpose |
|---|---|
| `conreg_events` | Event records and open/closed state |
| `conreg_members` | Member identity, contact, status, pricing fields (see `docs/architecture/pricing.md`) |
| `conreg_payments` | Payment headers |
| `conreg_payment_sessions` | Stripe session tracking |
| `conreg_payment_lines` | Per-member payment lines |
| `conreg_upgrades` | Membership upgrade records |
| `conreg_member_options` | Option selections |
| `conreg_member_addons` | Add-on selections |
| `conreg_member_clickup_options` | ClickUp option/task links |

## Model characteristics

- One row in `conreg_members` represents one member for one event (`eid`).
- Grouped registrations are linked through `lead_mid`.
- Soft deletion is represented by `is_deleted`.
- No Drupal entity type is defined for members.

## Submodule tables

| Submodule | Table |
|---|---|
| `conreg_airtable` | `conreg_airtable_members` |
| `conreg_discord` | `conreg_discord` |
| `conreg_planz` | `conreg_planz` |

## First Drupal entity types

`conreg_mailing_list` defines `conreg_subscription_rule`, the first actual Drupal
entity type anywhere in this codebase (everything else above is a custom table with a
storage service, per "No Drupal entity type is defined for members" above). It's a
**config** entity, deliberately with no database table: each rule is stored as a config
object, not a row.

Badge label printing later added `conreg_printer` and `conreg_print_job`
(`Drupal\conreg\Entity\Printer`/`PrintJob`) — the first **content** entities in this
codebase, each with a real base table. `conreg_print_job` carries an
`image_data` field: a base64-encoded PNG rendered once, at job-creation
time, by `Drupal\conreg\Service\LabelRenderer` — label rendering moved
into ConReg itself (previously the separate print agent's job), so the
print agent only rotates and prints what's already been rendered. See
`site-building/label-printing.md` for full field lists; new storage
going forward should follow this content-entity pattern rather than
adding more `hook_schema()` tables.

It also added a second **config** entity, `conreg_label_size`
(`Drupal\conreg\Entity\LabelSize`) — a named Dymo label size (width/
height in mm, plus a rotation override), global rather than per-event.
See `site-building/label-printing.md`.
