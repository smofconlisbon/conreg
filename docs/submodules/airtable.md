# Airtable (`conreg_airtable`)

## What it adds

| Area | Details |
|---|---|
| Config route | `conreg_config_airtable_options` |
| Config form class | `Drupal\conreg_airtable\ConfigAirTableForm` |
| Permission | `configure AirTable integration` |
| Table | `conreg_airtable_members` |

## Hook integration

- `conreg_airtable_convention_member_added()`
- `conreg_airtable_convention_member_updated()`
- `conreg_airtable_convention_member_deleted()`

These call `AirTable::addMembers()`, `updateMembers()`, and `deleteMember()`.

## Implementation note

`conreg_airtable.links.task.yml` uses plugin key
`conreg_clickup.config_airtable_options` while targeting Airtable route
`conreg_config_airtable_options`.
