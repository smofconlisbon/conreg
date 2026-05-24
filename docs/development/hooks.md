# Hooks API

## Declared hooks

`conreg.api.php` documents:

- `hook_convention_member_added($member)`
- `hook_convention_member_updated($member)`
- `hook_convention_member_deleted($member)`

## Emitted hooks

`src/Member.php` invokes:

- `convention_member_added`
- `convention_member_updated`
- `convention_member_deleted`

## Payload note

`conreg.api.php` documents `$member` as array, while runtime emitters pass a
`Drupal\conreg\Member` object in current implementations.
