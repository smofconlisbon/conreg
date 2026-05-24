# Submodules Overview

All submodules depend on `conreg` and extend behavior through routes, forms,
schema additions, and member lifecycle hooks.

## Submodule map

| Module | Main responsibility |
|---|---|
| `conreg_airtable` | Sync member records with Airtable |
| `conreg_badges` | Badge names list/export/print tools |
| `conreg_clickup` | Create/update ClickUp tasks from options |
| `conreg_discord` | Discord invite generation and email sending |
| `conreg_lookup` | Staff member lookup UI |
| `conreg_planz` | PlanZ/Zambia bridge and invite flow |
| `conreg_simplenews` | Packaging stub; parent module hosts logic |

## Hook propagation

```mermaid
flowchart LR
  member[Member save/delete] --> added[convention_member_added]
  member --> updated[convention_member_updated]
  member --> deleted[convention_member_deleted]
  added --> airtable
  updated --> airtable
  updated --> clickup
  updated --> planz
```
