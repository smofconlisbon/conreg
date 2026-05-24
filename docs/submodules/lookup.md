# Lookup (`conreg_lookup`)

## What it adds

| Area | Details |
|---|---|
| Route | `conreg_member_lookup` (`members/lookup/{eid}`) |
| Form | `Drupal\conreg_lookup\Form\LookupMemberForm` |
| Permission | `lookup members` |
| Dynamic menu | `LookupMenuDeriver` |

## Query style

Lookup uses custom SQL over `conreg_members` with AJAX updates. It does not add
entity- or Views-based lookup layers.
