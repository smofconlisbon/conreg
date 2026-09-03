# Requirements

## Platform

| Requirement | Source |
|---|---|
| Drupal core `^11.2 || ^12` | `conreg.info.yml` and most submodule `*.info.yml` |
| Drupal core `^11.3 || ^12` | `conreg_mailing_list`, `conreg_mailerlite`, `conreg_simplenews` `*.info.yml` — narrower than the rest, due to core plugin-autowiring and entity-attribute features they depend on |
| Contrib module `key` | `conreg.info.yml` dependency — Stripe keys and per-event category keys are stored as Key entities, not plaintext config |
| PHP package `stripe/stripe-php` | `composer.json` |

## Drupal capabilities used

- Form API and controllers for public/admin workflows.
- Config API with per-event keys (`conreg.settings.{eid}`).
- Database API over custom tables from `hook_schema()`.
- Dynamic permissions via `permission_callbacks`.

## Optional integrations

| Integration | Module |
|---|---|
| Airtable API | `conreg_airtable` |
| ClickUp API | `conreg_clickup` |
| Discord invite bot | `conreg_discord` |
| PlanZ database bridge | `conreg_planz` |
| Mailing-list subscription framework | `conreg_mailing_list` |
| MailerLite API | `conreg_mailerlite` |
| Simplenews subscription linking | `conreg_simplenews` (`MailingListProvider` plugin) — also requires the contrib `simplenews` module |
