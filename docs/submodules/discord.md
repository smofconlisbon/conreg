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
- Sends invite emails using ConReg email/token patterns.

## Operational behavior

Invite generation can target paid/approved members and supports limited batch
operations through the admin form.
