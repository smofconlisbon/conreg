# Module Enablement

## Module inventory

| Extension | Purpose | Declared dependencies |
|---|---|---|
| `conreg` | Core convention registration workflows | none |
| `conreg_airtable` | Sync member data to Airtable | `conreg` |
| `conreg_badges` | Badge list/export/print flows | `conreg` |
| `conreg_clickup` | Create ClickUp tasks from options | `conreg` |
| `conreg_discord` | Generate Discord invites and emails | `conreg` |
| `conreg_lookup` | Staff lookup of member records | `conreg` |
| `conreg_planz` | PlanZ/Zambia integration | `conreg` |
| `conreg_simplenews` | Packaging stub for Simplenews integration | `conreg` |

## Enable order

1. Enable `conreg`.
2. Enable supporting submodules.
3. Assign required permissions for each enabled module.

## Important implementation note

`conreg_simplenews` itself is empty; Simplenews behavior is implemented in the
parent `conreg.module` via form alter and submit logic.
