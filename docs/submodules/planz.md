# PlanZ (`conreg_planz`)

## What it adds

| Area | Details |
|---|---|
| Routes | `conreg_config_planz_options`, `conreg_config_planz_admin` |
| Forms | `ConfigPlanZForm`, `PlanZAdminForm` |
| Permissions | `configure PlanZ integration`, `PlanZ admin` |
| Table | `conreg_planz` |

## Integration model

- Connects to an external database target (`Database::getConnection(..., 'planz')`).
- Generates badge IDs and syncs users through `PlanZ` and `PlanZUser`.
- Sends invite email via ConReg mail token flow.

## Implementation note

The module defines `conreg_planz_convention_member_inserted()`, but parent
ConReg emits `convention_member_added` on insert and does not emit
`convention_member_inserted`.
