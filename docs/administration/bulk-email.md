# Bulk Email

## Routes

| Route | Purpose |
|---|---|
| `conreg_admin_bulk_email` | Compose a bulk email for an event audience |
| `conreg_admin_bulk_email_send` | Controller endpoint that executes sends per target member |

## Config and templates

- Defaults are stored under `bulk_email.*` in event config.
- Email rendering still uses ConReg template/token pipeline.

## Permission

- `bulk email sending`
