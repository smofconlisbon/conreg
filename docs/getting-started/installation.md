# Installation

## Module placement

Add this repository as a Drupal module (for example under a custom or contrib
module path used by your project).

## Composer dependency

`conreg` requires `stripe/stripe-php` (declared in `composer.json`) and the
contrib `key` module (declared in `conreg.info.yml`) - Stripe keys and
per-event category keys are stored as Key entities rather than plaintext
config, so `key` is enabled automatically as a dependency of `conreg`.

Typical workflow from the module root:

```bash
composer install
```

## Enable modules

Enable `conreg` first, then optional submodules as needed:

- `conreg_airtable`
- `conreg_badges`
- `conreg_clickup`
- `conreg_discord`
- `conreg_lookup`
- `conreg_planz`
- `conreg_mailing_list` — enable before `conreg_mailerlite`/`conreg_simplenews`, both
  declare it as a dependency
- `conreg_mailerlite`
- `conreg_simplenews` — also requires the contrib `simplenews` module

## Initial configuration

After installing selected modules, set up as follows.

**Stripe keys:**

- Copy the publishable and secret key out of the Stripe dashboard. Store the
  secret key somewhere outside exported config, e.g. a local file outside
  the webroot, if you plan to use the Key module's "File" provider.
- Create two Key entities under `admin/config/system/keys/add` - one for the
  publishable key, one for the secret key. Each must use a key type from the
  "Authentication" group (e.g. "Authentication"); the Payment System tab's
  key-select fields only list keys of that type, so a key created with a
  different type silently won't appear in the dropdown in the next step.
  Avoid the "Configuration" provider for the secret key - it stores the
  value in plain exported config, which is what moving to the Key module was
  meant to avoid.
- Give each key a descriptive label (e.g. `Stripe test secret - Worldcon`)
  rather than a generic one - the key list isn't scoped per event, so on a
  site with several events, or both test and live keys, a generic name makes
  it easy to select the wrong key later.

**Event setup:**

- Open `admin/config/conreg/events`.
- You may use the default event, or create or clone a new event.
- Select "Configure" from the event drop-down, or from the Admin Navigation,
  taking you to `admin/config/conreg/{eid}`.
- Under Payment System, select the Mode (Test or Live), and select the two
  keys you created above.
- Once both keys are selected, a "Key status" line appears confirming Stripe
  accepted the secret key and that both keys agree with each other and with
  the selected Mode. See [Stripe and Payments](../site-building/payments.md)
  for what this check does and doesn't verify.
- Review the other settings.
