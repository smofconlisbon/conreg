# Configuration

## Per-event config pattern

Most runtime behavior is keyed by event-specific config objects:

- `conreg.settings.{eid}`
- core key schema in `config/schema/conreg.schema.yml`

## Schema-backed config groups

| Group | Examples |
|---|---|
| `payments` | Stripe mode/keys, currency, auto-approve |
| `member.classes` | Field labels, mandatory flags, max lengths |
| `member.types` | Type code, price, default days, class mapping |
| `thanks`, `member_check`, `member_edit` | User-facing content and labels |
| `conreg_options` | Option groups and options |
| `bulk_email`, `confirmation` | Email templates and sender data |

## Runtime integration groups

Integration modules also persist settings on `conreg.settings.{eid}`.
These runtime keys are not fully described in `conreg.schema.yml`:

- `airtable.*`
- `clickup_option_groups`
- `discord.*`
- `planz.*`
- `simplenews.options`

## Pipe-delimited lists

Several config values are code+label lists where codes must stay stable
(`badge_types`, `days`, communications/display options).
