# Email Templates

## Template locations

| Area | Config keys |
|---|---|
| Confirmation | `confirmation.*` |
| Membership check | `member_check.*` |
| Thank-you page | `thanks.*` |
| Bulk email defaults | `bulk_email.*` |
| Standalone templates | `conreg.email_templates` config object |

## Admin route

`conreg_config_email_templates` manages email template definitions.

## Builder notes

- Template content supports ConReg tokens.
- Format fields are stored with template content (`*_format` keys).
