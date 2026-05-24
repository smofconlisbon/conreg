# Requirements

## Platform

| Requirement | Source |
|---|---|
| Drupal core `^10.1 || ^11.0` | `conreg.info.yml` and submodule `*.info.yml` |
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
| Simplenews subscription linking | parent `conreg.module` logic |
