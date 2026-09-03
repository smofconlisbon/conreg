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

## `#[LegacyHook]` procedural wrappers (still present, now unnecessary)

Every hook in core `conreg` (`ConregHooks`, `ConregThemeHooks`,
`ConregTokenHooks`) and in `conreg_airtable`, `conreg_clickup`,
`conreg_discord`, and `conreg_planz` is paired with a procedural function in
that module's `.module` file, tagged `#[LegacyHook]`, that just calls the
`#[Hook]`-tagged class method:

```php
#[LegacyHook]
function conreg_theme(): array {
  return \Drupal::service(ConregThemeHooks::class)->theme();
}
```

This exists because attribute-based hooks only work from Drupal 11.1
onward — Drupal 11.1+'s hook collector skips a function tagged
`#[LegacyHook]` (no double execution), while an older core, unaware
attributes exist, just calls the procedural function as it always has.

Now that every affected module requires `^11.2` or narrower (see
`docs/getting-started/requirements.md`), every supported install already has
attribute-hook support, so this wrapper layer is no longer needed — but it
hasn't been removed yet. That cleanup is tracked as its own follow-up issue
rather than done as part of the version-requirement bump. Until then, keep
adding new hooks the same paired way, matching the existing pattern.
