# Stripe and Payments

## Config keys

Payment behavior is primarily driven by `payments.*` keys in
`conreg.settings.{eid}`:

- `system`, `mode`, `public_key`, `private_key`
- `currency`, `symbol`
- `auto_approve`
- `show_remaining`

## Credentials via the Key module

`payments.public_key` and `payments.private_key` each store a **Key entity
ID**, not the Stripe key itself - keys are per-event, unlike most other
ConReg credentials. `StripeService::resolveKey()` resolves an ID to its
actual value at request time via `KeyRepositoryInterface::getKey($keyId)
?->getKeyValue()`; an unset ID, an ID with no matching Key, or a Key whose
provider fails to produce a value all resolve to an empty string rather than
throwing, so a misconfigured event simply fails at the Stripe API call
instead of breaking the page.

`EventConfig`'s Payment System tab uses `#type => 'key_select'` (filtered to
`type_group: 'authentication'` keys) for both fields, not plain textfields.
When both keys are set, the tab also shows a live "Key status" line -
`StripeService::verifyKeys()` makes a cheap Stripe API call (`balance
retrieve`) with the secret key, then cross-checks test/live mode three ways:
the publishable key's `pk_test_`/`pk_live_` prefix against the secret key's
`sk_test_`/`sk_live_` prefix, and both against the tab's own `mode` field
(`payments.mode`) - a key pair in the wrong mode for the configured setting
is a realistic misconfiguration neither the API call nor the key-to-key
comparison alone would catch.

**What this check deliberately doesn't verify:** whether the publishable and
secret key actually belong to the *same* Stripe account. Stripe has no
server-side API for that - a publishable key isn't valid for authenticated
backend requests, so there's no equivalent call for it to mirror the secret
key's `balance retrieve`. A same-mode pair made of a secret key from one
Stripe account and a publishable key from an unrelated one still passes.
The success message says as much rather than implying full coverage; a real
test payment is the only way to confirm the pair actually works together.

**Upgrading from plaintext keys:** sites that had `payments.public_key`/
`private_key` stored as plaintext before this change had them cleared by
`conreg_update_9006()`, rather than auto-migrated into new Key entities -
picking a Key provider/type is a site-maintainer decision, and creating
entities from secrets already sitting in exportable config wouldn't reduce
the exposure that prompted the migration. Stripe payments for an event stop
working until an admin creates Key entities for that event's keys and
reselects them on the Payment System tab.

## Runtime tables

| Table | Role |
|---|---|
| `conreg_payments` | Payment header/status |
| `conreg_payment_sessions` | Stripe session state |
| `conreg_payment_lines` | Line-level amounts and members |

## UI flow

- Registration submits members and payment records.
- Checkout route processes payment session and completion.
