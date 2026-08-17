# Theming

## Member type cards

The Registration form's member type selector renders through two templates:

| Template | Renders |
|---|---|
| `templates/member-type-cards.html.twig` | The radiogroup wrapper (title, container, error message) |
| `templates/member-type-card.html.twig` | One card (name, price, description, selected/disabled state) |

This plural-wraps-singular split matches Drupal core's own convention for the
same relationship, e.g. `menu-local-tasks.html.twig` /
`menu-local-task.html.twig` and `views-view-fields.html.twig` /
`views-view-field.html.twig`.

`ConregThemeHooks::preprocessMemberTypeCards()` (`src/Hook/ConregThemeHooks.php`,
see `docs/development/hooks.md`) builds each card as a themeable render array
with a per-type suggestion:

```php
'#theme' => ['member_type_card__' . $suggestion, 'member_type_card'],
```

`$suggestion` is the member type's code, lowercased with non-alphanumeric
characters collapsed to `_` (e.g. type code `A` → suggestion `a`).

## Overriding one type's card

To style a specific membership type differently, add
`member-type-card--{suggestion}.html.twig` (e.g. `member-type-card--a.html.twig`
for type code `A`) to your **theme's** `templates/` directory. Drupal discovers
it automatically — no extra registration needed, the same as any other
`hook_theme_suggestions_alter()`-style override.

**This only works from a theme, not a module.** Drupal's template-suggestion
discovery (`TwigThemeEngine::theme()` → `drupal_find_theme_templates()`) only
scans the active theme's (and base themes') `templates/` directory — a module
that ships a template file with no `hook_theme()` of its own is never scanned,
even if the filename matches the suggestion pattern exactly. A module can
still provide an override, but only by implementing
`hook_theme_suggestions_member_type_card_alter()` itself, not by dropping a
file in and hoping it's picked up.

## Day options

When a selected member type has per-day pricing, the day-pricing checkboxes
rendered inside its card (see `#day_options` above) follow the same
plural-wraps-singular split:

| Template | Renders |
|---|---|
| `templates/member-day-options.html.twig` | The group wrapper (title, container, error message) |
| `templates/member-day-option.html.twig` | One day (checkbox, price, description) |

Each day renders as `[ ] {price} {description}` — e.g. `[ ] €25 Saturday` —
where the description is the admin-entered text from the day's
configuration and the price comes from that day's configured price ("Free"
for a zero price, omitted entirely if the day has no price data).

`ConregThemeHooks::preprocessMemberDayOptions()` (`src/Hook/ConregThemeHooks.php`)
builds each day as a themeable render array with a per-day suggestion, using
the same suggestion algorithm as the member type cards above:

```php
'#theme' => ['member_day_option__' . $suggestion, 'member_day_option'],
```

## Overriding one day's option

To style a specific day differently, add
`member-day-option--{suggestion}.html.twig` (e.g. `member-day-option--fr.html.twig`
for day code `Fr`) to your **theme's** `templates/` directory — same
mechanism, and the same theme-only caveat, as overriding one type's card
above.
