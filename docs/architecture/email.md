# Email and Tokens

## Email composition

| Component | Responsibility |
|---|---|
| `conreg_mail()` | Delegates templated mail creation |
| `ConregEmailer` | Builds email content |
| `ConregTokens` | Expands member/event/login tokens |
| `SimpleConregPhpMail` | ConReg-provided mail plugin (`conreg_php_mail`) |

## Email-driven workflows

- Registration confirmation and admin notification.
- Membership check confirmation email.
- Portal/login access links.
- Bulk email from admin routes.
- Submodule invitation mails (for example PlanZ/Discord) via core mail service.

## Token usage

Token expansion is used in event templates and in integration templates
(examples include `[event_name]`, `[member_details]`, `[login_url]`).

## Implementation note

`SimpleConregPhpMail` exists as a module mail plugin, but it is not implicitly
the site-wide default mail backend unless configured in Drupal mail settings.
