# Config Keys Reference

## Main config objects

| Config object | Notes |
|---|---|
| `conreg.settings.{eid}` | Event-scoped runtime configuration |
| `conreg.clickup` | Global ClickUp OAuth/token settings |
| `conreg_mailerlite.settings` | Global MailerLite API key, shared by all events (schema `conreg_mailerlite/config/schema/conreg_mailerlite.schema.yml`) |
| `easy_email.easy_email_type.*` | Email templates (config entities, one per bundle) - not nested under `conreg.settings.{eid}`; see `site-building/email-templates.md` |

## Schema-defined groups (`config/schema/conreg.schema.yml`)

| Group | Contents |
|---|---|
| `payments` | Stripe and payment behavior |
| `checkin` | Check-in defaults, `label_printing_enabled`, `print_api_key` (Key entity ID for the print job API) - see `site-building/label-printing.md` |
| `badge_types`, `badge_name_options`, `days` | Pipe-delimited code/label lists |
| `member.classes`, `member.types` | Registration field and type models |
| `submit`, `thanks`, `member_check`, `member_edit` | User-facing labels/messages (`member_check` also holds `confirm_easy_email_type`/`unknown_easy_email_type` references) |
| `confirmation`, `bulk_email` | `easy_email_type` reference (which `EasyEmailType` to use) and sender metadata - see `site-building/email-templates.md` |
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

`discord.*` (written by `conreg_discord`'s `ConfigDiscordForm`, including its
`easy_email_type` invite-template reference) **is** fully schema-defined,
nested under `conreg.settings.{eid}` like the groups above.

`planz.*` is not nested under `conreg.settings.{eid}` at all - despite the
similar name, `conreg_planz`'s `ConfigPlanZForm` reads/writes a genuinely
separate config object, literally named `conreg.settings.<eid>.planz`
(schema `conreg.settings.*.planz`), including its own
`email.easy_email_type` invite-template reference.

## Mailing-list config entity

`conreg_subscription_rule` (`conreg_mailing_list`) is a **config entity**, not a
runtime key group — each subscription rule is its own config object
(`conreg_mailing_list.conreg_subscription_rule.*`, schema
`conreg_mailing_list/config/schema/conreg_mailing_list.schema.yml`), scoped to an event
via its own `eid` property rather than nested under `conreg.settings.{eid}`.
