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
- Sends the invite email via `ConregEmailSender`, using the `EasyEmailType`
  configured at `conreg.settings.<eid>.planz:email.easy_email_type`
  (`ConfigPlanZForm`). PlanZ-specific tokens (`[conreg-planz:user]`,
  `[conreg-planz:password]`, `[conreg-planz:url]`, implemented in
  `ConregPlanzHooks`) are passed as extra token data alongside the usual
  `[conreg:*]` tokens - see `architecture/email.md`.

## Implementation note

The module defines `conreg_planz_convention_member_inserted()`, but parent
ConReg emits `convention_member_added` on insert and does not emit
`convention_member_inserted`.
