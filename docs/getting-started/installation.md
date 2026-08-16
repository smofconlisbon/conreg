# Installation

## Module placement

Add this repository as a Drupal module (for example under a custom or contrib
module path used by your project).

## Composer dependency

`conreg` requires `stripe/stripe-php` (declared in `composer.json`).

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

- Open `admin/config/conreg/events`.
- Create or clone an event.
- Configure event tabs under `admin/config/conreg/{eid}`.
