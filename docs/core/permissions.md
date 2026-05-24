# Core Permissions

## Key permission groups

| Purpose | Permissions |
|---|---|
| Public/member access | `convention registration`, `view public members`, `check membership`, `member portal` |
| Event/config management | `configure convention registration`, `configure convention add-ons`, `configure convention email templates` |
| Member operations | `manage convention members`, `check in convention members`, `fan table registration` |
| Reporting/export | `view membership details`, `view membership options`, `view membership add-ons`, `view child members`, `view membership summary` |
| Comms | `bulk email sending`, `manage mailout lists` |

## Dynamic permissions

`permission_callbacks` includes `FieldOptionPermissions::permissions`,
which creates per-option, per-event permissions named:

`view field option {optid} event {eid}`.

## Implementation notes

- `conreg.permissions.yml` also defines `view programme membership details` and
  `view volunteer membership details`; these permissions currently do not map to
  dedicated routes.
- Badge-related permissions are defined in
  `conreg_badges/conreg_badges.permissions.yml`.
