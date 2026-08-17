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

`conreg` core implements `hook_theme()` and `hook_preprocess_HOOK()` (for
`member_type_cards` and `member_day_options`) the same way:
`ConregThemeHooks::theme()`, `ConregThemeHooks::preprocessMemberTypeCards()`,
and `ConregThemeHooks::preprocessMemberDayOptions()`
(`src/Hook/ConregThemeHooks.php`), tagged `#[Hook('theme')]`,
`#[Hook('preprocess_member_type_cards')]`, and
`#[Hook('preprocess_member_day_options')]`.

## Drupal 10 compatibility: `#[LegacyHook]`

Attribute-based hooks only exist from Drupal 11.1. `conreg_mailing_list`
requires `^11.3 || ^12`, so it can use `#[Hook]` alone. Core `conreg` requires
`^10.1 || ^11.0`, so its OOP hooks (`ConregThemeHooks`) need a matching
procedural implementation too, tagged `#[LegacyHook]`, so Drupal 11.1+'s hook
collector skips it (no double execution) while Drupal 10 — unaware attributes
exist — just calls the procedural function as it always has:

```php
#[LegacyHook]
function conreg_theme(): array {
  return \Drupal::service(ConregThemeHooks::class)->theme();
}
```

The class is registered as a service in `conreg.services.yml` using its FQCN
as the service ID, matching what Drupal 11.1+'s hook collector would auto-
register — this keeps `\Drupal::service(ConregThemeHooks::class)` working
identically on both versions. When adding a new hook to core `conreg` (not a
submodule that already requires 11.3+), follow this pattern rather than
`#[Hook]` alone.
