# Simplenews (`conreg_simplenews`)

## Module shape

`conreg_simplenews` is a packaging stub: it has `conreg_simplenews.info.yml`
and an empty module file.

## Actual implementation location

Simplenews behavior is implemented in parent `conreg.module`:

- `hook_form_alter()` for newsletter edit/add forms.
- `conreg_simplenews_form_submit()` to store event-specific simplenews options.
- Subscription updates through `simplenews.subscription_manager`.

## Config footprint

Event-level options are stored under `conreg.settings.{eid}` at
`simplenews.options`.
