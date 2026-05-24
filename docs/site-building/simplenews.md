# Simplenews Linking

## Where integration runs

Simplenews integration logic is in parent `conreg.module`, not in
`conreg_simplenews` module code.

## Builder flow

1. Edit a Simplenews newsletter form.
2. Configure per-event ConReg controls added by form alter.
3. Choose active state and allowed communications methods.
4. Save to update `simplenews.options` in each `conreg.settings.{eid}`.

## Behavior summary

On submit, ConReg can subscribe or unsubscribe member emails for the newsletter
based on configured communications method filters.
