# Bulk Email

## Routes

| Route | Purpose |
|---|---|
| `conreg_admin_bulk_email` | Compose a bulk email for an event audience |
| `conreg_admin_bulk_email_send` | Controller endpoint that executes sends per target member |

## Config and templates

- The template used is an `EasyEmailType` referenced by
  `bulk_email.easy_email_type` in event config (see
  `site-building/email-templates.md`).
- Sending goes through the shared `ConregEmailSender`/token-bridging
  pipeline described in `architecture/email.md`.

## Permission

- `bulk email sending`
