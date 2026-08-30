# Email and Tokens

<!-- cspell:ignore mailsystem -->

Email sending goes through the `drupal/easy_email` contrib module. Each
template is an `EasyEmailType` config entity (a *bundle*); sending an email
means building an `EasyEmail` content entity of that bundle
(`EmailHandlerInterface::createEmail()`) and handing it to
`EmailHandlerInterface::sendEmail()`. There is no bundled-in categorization
of templates by purpose - the site maintainer picks whichever `EasyEmailType`
makes sense for each config field; ConReg does not filter the options.

## Email composition

| Component | Responsibility |
|---|---|
| `ConregEmailSender` | Central service used by nearly every call site: builds an `EasyEmail` entity in the right language, stamps the conreg context fields, populates a real plain-text body, and sends |
| `RegistrationConfirmationMailer` | Shared "confirmation + copy_us + copy_email_to" three-email pattern used by `Checkout` and `FanTable` |
| `EmailTokenContext` | Rebuilds the `event`/`members` token data (the same shape ConReg has always resolved tokens against) from an `EasyEmail` entity's `field_conreg_eid`/`field_conreg_mid` |
| `ConregEasyEmailFieldHooks` | `hook_entity_base_field_info()` - adds `field_conreg_eid`, `field_conreg_mid`, `field_conreg_extra_token_data` to the `easy_email` entity type |
| `ConregEasyEmailTemplateValidationHooks` | Warns (non-blocking) on the `EasyEmailType` edit form if `[conreg:member-details]` is present but the chosen text format would strip its `<table>` markup |
| `MemberPresenter` | Loads/resolves member data (labels, currency, login links) for tokens |
| `ConregTokenHooks` | Implements `hook_token_info()`/`hook_tokens()` for the `conreg`/`conreg-member` token types |
| `MemberDetailsFormatter` | Builds the `[conreg:member-details]` HTML table / plain-text report |

Submodules that send their own invitation mail (Discord, PlanZ) implement
their own `hook_token_info()`/`hook_tokens()` (types `conreg-discord`,
`conreg-planz`) and pass their extra context through
`field_conreg_extra_token_data` (JSON, set via `ConregEmailSender::build()`'s
`$extraTokenData` argument) rather than the token hooks knowing about them
directly.

## Why the token data needs bridging

Easy Email's `EmailTokenEvaluator::replaceTokens()` always resolves tokens
against `['easy_email' => $email]` only - it has no concept of ConReg's
`event`/`members` token data. `ConregTokenHooks::tokens()` detects this case
(`$data['easy_email']` present, `$data['event']`/`$data['members']` absent)
and calls `EmailTokenContext::buildFromEmail($email)`, which reads
`field_conreg_eid`/`field_conreg_mid` off the entity and rebuilds the same
`event`/`members` array `EmailTokenContext::build()` would produce directly.
The submodule token hooks (`ConregPlanzHooks`, `ConregDiscordTokenHooks`)
follow the same pattern, reading their own extra context from
`field_conreg_extra_token_data` instead.

## Sending a conreg-flavored email

The common pattern, used by `Checkout`, `FanTable`, `BulkMailController`,
`CheckMember`, `conreg_planz`, and `conreg_discord`:

```php
$email = $this->emailSender->send(
  bundle: $easyEmailTypeId,   // an EasyEmailType ID, e.g. from event config
  recipient: $address,
  eid: $eid,
  mids: $mids,                // member ID(s) for [conreg:member(s):*] tokens
  langcode: $member->language, // resolves translated EasyEmailType text
  extraTokenData: [],          // e.g. ['planz' => [...]] for submodule tokens
);
```

`ConregEmailSender::build()` wraps `createEmail()` in
`LanguageManagerInterface::setConfigOverrideLanguage()` so a member's own
language picks up the matching translated `EasyEmailType` text (mirroring
the pattern core's `user` module uses in `UserHooks::mail()`), then calls
`populatePlainBody()` to resolve `[conreg:*]` tokens a second time with the
`plain_text` token option before running the result through
`MailFormatHelper::htmlToText()` - Easy Email's own HTML-to-plain-text
conversion runs too late (after tokens are already resolved into markup) to
render `[conreg:member-details]` as readable indented text. Because of this,
every conreg-authored `EasyEmailType` must have "Generate plain text body"
turned off.

`MemberEmail.php` (the "email a member" admin form) additionally passes
`saveEmailEntity: TRUE` when sending, so each ad hoc admin-sent email is kept
as a log entry (`/admin/content/email`, Easy Email's own log), unlike the
other, higher-volume call sites.

## Email-driven workflows

- Registration confirmation and admin notification (`RegistrationConfirmationMailer`).
- Membership check confirmation email (`CheckMember`).
- Portal/login access links (embedded via `[conreg:member:login-url]`).
- Ad hoc "email a member" from the admin members list, with a live,
  debounced preview.
- Bulk email from admin routes.
- Submodule invitation mails (PlanZ, Discord).

## Token usage

Tokens are expanded via Drupal core's Token API (`\Drupal::token()`), not a
custom parser. Event/member tokens are namespaced under `conreg` (examples:
`[conreg:event-name]`, `[conreg:member:first-name]`,
`[conreg:member-details]`, `[conreg:member:login-url]`); Discord/PlanZ add
their own `conreg-discord`/`conreg-planz` types (`[conreg-discord:invite-url]`,
`[conreg-planz:user]`/`[conreg-planz:password]`/`[conreg-planz:url]`). The
`EasyEmailType` edit form shows the contrib Token module's own token
browser, so authors can browse every available token, not just conreg's.

`[conreg:member-details]` renders as an HTML `<table>` with inline CSS (email
clients don't reliably honor `<style>` blocks), grouped by registration date
- members who joined on the same date share one "Registered on ..." heading
and table; a date change breaks into a new heading and table, but member
numbering (`Member 1`, `Member 2`, ...) keeps counting across the break. When
resolved with the `plain_text` token option (as `populatePlainBody()` does),
it renders as an indented plain-text block instead.

## Transport

ConReg does not choose a mail transport itself - production sites configure
their own Symfony Mailer-based transport for Easy Email to use (via the
`mailsystem` module or equivalent). `drupal/symfony_mailer_lite` is a
require-dev dependency only, providing a transport for local development and
automated tests.
