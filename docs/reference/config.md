# Config Keys Reference

## Main config objects

| Config object | Notes |
|---|---|
| `conreg.settings.{eid}` | Event-scoped runtime configuration |
| `conreg.email_templates` | Shared template storage |
| `conreg.clickup` | Global ClickUp OAuth/token settings |
| `conreg_mailerlite.settings` | Global MailerLite API key, shared by all events (schema `conreg_mailerlite/config/schema/conreg_mailerlite.schema.yml`) |

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

## Mailing-list config entity

`conreg_subscription_rule` (`conreg_mailing_list`) is a **config entity**, not a
runtime key group — each subscription rule is its own config object
(`conreg_mailing_list.conreg_subscription_rule.*`, schema
`conreg_mailing_list/config/schema/conreg_mailing_list.schema.yml`), scoped to an event
via its own `eid` property rather than nested under `conreg.settings.{eid}`.
