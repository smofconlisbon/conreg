# Data Model

## Core tables

| Table | Purpose |
|---|---|
| `conreg_events` | Event records and open/closed state |
| `conreg_members` | Member identity, contact, status, pricing fields |
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
