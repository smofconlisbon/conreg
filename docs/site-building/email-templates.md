# Email Templates

Templates are `EasyEmailType` config entities, managed by the `easy_email`
contrib module - there is no ConReg-specific template editor. Each
`EasyEmailType` is one bundle: a subject, an HTML body, an optional plain
body, and sender/reply-to defaults, all independently translatable.

## Managing templates

Manage templates at **Structure → Email templates**
(`entity.easy_email_type.collection`, `/admin/structure/email-templates`),
gated by Easy Email's own `administer email types` permission. Every ConReg
config field that references a template (see below) links straight to this
page via its `#description`.

There is no built-in categorization of templates by purpose - any
`EasyEmailType` can be selected in any of the config fields below. It's the
site maintainer's responsibility to pick sensibly (e.g. don't point the
"membership check - not found" field at a template written for registration
confirmation).

## Where templates are selected

| Area | Config key | Set in |
|---|---|---|
| Registration confirmation (and its admin-resend/bulk-email default) | `confirmation.easy_email_type` | `EventConfig` |
| Per-member-type confirmation override | `member.types.<type>.confirmation.easy_email_type` | `MemberTypes` |
| Membership check - member found | `member_check.confirm_easy_email_type` | `EventConfig` |
| Membership check - no member found | `member_check.unknown_easy_email_type` | `EventConfig` |
| Bulk email | `bulk_email.easy_email_type` | `BulkEmail` |
| PlanZ invite | `conreg.settings.<eid>.planz:email.easy_email_type` | `conreg_planz`'s `ConfigPlanZForm` |
| Discord invite | `discord.easy_email_type` | `conreg_discord`'s `ConfigDiscordForm` |

All of these are plain `easy_email_type` reference keys (see
`config/schema/conreg.schema.yml`) - a bare string holding an `EasyEmailType`
ID, not stored template text.

The "email a member" admin form (`MemberEmail`) additionally offers a
free-choice picklist of every `EasyEmailType`, since it's used for ad hoc
resends and one-off messages rather than one fixed purpose.

## Authoring notes

- Template bodies use `[conreg:*]`/`[conreg-member:*]` tokens (and
  `[conreg-planz:*]`/`[conreg-discord:*]` where relevant) - see
  `architecture/email.md` for the full token reference and how they resolve.
- **Use a text format with no "Limit allowed HTML tags" restriction** (e.g.
  Full HTML) for any template that includes `[conreg:member-details]` - it
  renders as an HTML `<table>`, and a restrictive format's tag allowlist can
  silently strip it down to unformatted text. Saving a template with this
  combination shows a non-blocking warning if the chosen format would strip
  the table.
- Turn **off** "Generate plain text body" on every conreg-authored template.
  ConReg populates the plain-text body itself (see
  `ConregEmailSender::populatePlainBody()` in `architecture/email.md`) so
  that `[conreg:member-details]` renders as readable indented text instead
  of Easy Email's generic HTML-to-text conversion squashing the table into a
  run-on line.
- Sent emails are logged at **Content → Email** (`/admin/content/email`) -
  full logging for admin ad hoc sends (`MemberEmail`); other call sites send
  without keeping a log entry by default.
