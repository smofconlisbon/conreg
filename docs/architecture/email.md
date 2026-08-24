# Email and Tokens

## Email composition

| Component | Responsibility |
|---|---|
| `conreg_mail()` | Delegates templated mail creation |
| `ConregEmailer` | Builds email content |
| `MemberPresenter` | Loads/resolves member data (labels, currency, login links) for tokens |
| `ConregTokenHooks` | Implements `hook_token_info()`/`hook_tokens()` for the `conreg` token type, via Drupal core's Token API |
| `MemberDetailsFormatter` | Builds the `[conreg:member-details]` report table |
| `SimpleConregPhpMail` | ConReg-provided mail plugin (`conreg_php_mail`) |

Submodules that send their own invitation mail (Discord, PlanZ) implement
their own `hook_token_info()`/`hook_tokens()` (types `conreg-discord`,
`conreg-planz`) and pass their extra context to `ConregEmailer` via
`$params['token_data']`, rather than `ConregEmailer` knowing about them.

## Email-driven workflows

- Registration confirmation and admin notification.
- Membership check confirmation email.
- Portal/login access links.
- Bulk email from admin routes.
- Submodule invitation mails (for example PlanZ/Discord) via core mail service.

## Token usage

Tokens are expanded via Drupal core's Token API (`\Drupal::token()`), not a
custom parser. Event/member tokens are namespaced under `conreg` (examples:
`[conreg:event-name]`, `[conreg:member:first-name]`,
`[conreg:member-details]`, `[conreg:member:login-url]`); Discord/PlanZ add
their own `conreg-discord`/`conreg-planz` types. Admin form fields that
accept templates show a "Browse available tokens" link (the contrib Token
module's token tree) alongside a description, rather than a hardcoded list.

## Implementation note

`SimpleConregPhpMail` exists as a module mail plugin, but it is not implicitly
the site-wide default mail backend unless configured in Drupal mail settings.
