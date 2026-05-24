# Config Keys Reference

## Main config objects

| Config object | Notes |
|---|---|
| `conreg.settings.{eid}` | Event-scoped runtime configuration |
| `conreg.email_templates` | Shared template storage |
| `conreg.clickup` | Global ClickUp OAuth/token settings |

## Schema-defined groups (`config/schema/conreg.schema.yml`)

| Group | Contents |
|---|---|
| `payments` | Stripe and payment behavior |
| `badge_types`, `badge_name_options`, `days` | Pipe-delimited code/label lists |
| `member.classes`, `member.types` | Registration field and type models |
| `submit`, `thanks`, `member_check`, `member_edit` | User-facing labels/messages |
| `confirmation`, `bulk_email` | Template and sender metadata |
| `conreg_options` | Option group definitions |

For typed key definitions in these groups, see
`config/schema/conreg.schema.yml`.

## Runtime integration groups (stored on `conreg.settings.{eid}`)

The following groups are written by module forms at runtime, but are not
fully represented in `config/schema/conreg.schema.yml`:

| Group | Written by |
|---|---|
| `airtable.*` | `conreg_airtable` config form |
| `clickup_option_groups` | `conreg_clickup` options form |
| `discord.*` | `conreg_discord` config form |
| `planz.*` | `conreg_planz` config form |
| `simplenews.options` | Parent `conreg.module` form submit logic |
