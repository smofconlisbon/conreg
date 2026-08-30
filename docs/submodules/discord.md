# Discord (`conreg_discord`)

## What it adds

| Area | Details |
|---|---|
| Config route | `conreg_config_discord_invitebot` |
| Config form | `Drupal\conreg_discord\Form\ConfigDiscordForm` |
| Permission | `configure discord invitebot` |
| Table | `conreg_discord` |

## Functional scope

- Stores invite codes keyed by member ID.
- Generates invite links through Discord API helper class.
- Sends the invite email via `ConregEmailSender`, using the `EasyEmailType`
  configured at `discord.easy_email_type` (`ConfigDiscordForm`). The
  Discord-specific `[conreg-discord:invite-url]` token (implemented in
  `ConregDiscordTokenHooks`) is passed as extra token data alongside the
  usual `[conreg:*]` tokens - see `architecture/email.md`.

## Operational behavior

Invite generation can target paid/approved members and supports limited batch
operations through the admin form.
