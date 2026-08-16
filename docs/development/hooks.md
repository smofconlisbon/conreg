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

## OOP hook implementations

`conreg_mailing_list` implements `convention_member_added` using Drupal's newer
attribute-based hook style instead of a procedural `hook_convention_member_added()`
function: `ConregMailingListHooks::memberAdded()`
(`conreg_mailing_list/src/Hook/ConregMailingListHooks.php`), tagged
`#[Hook('convention_member_added')]`, takes a typed `Member $member` parameter
(consistent with the payload note above) and fans matching members out to the
mailing-list subscription queue.
