# Getting Started Overview

This section covers install prerequisites, module enablement, and what to
configure first for an event.

## First-run sequence

1. Add the module code to your Drupal codebase.
2. Install PHP dependency from module `composer.json` (`stripe/stripe-php`).
3. Enable `conreg` and any needed submodules.
4. Grant permissions from `conreg.permissions.yml` and submodule permission files.
5. Create/configure event data at `admin/config/conreg/events`.

## Key implementation context

- ConReg is config- and table-driven; each event uses `conreg.settings.{eid}`.
- Member records are not Drupal entities; they are stored in `conreg_members`.
