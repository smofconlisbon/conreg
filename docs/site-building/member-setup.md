# Member Classes and Types

## Classes vs types

| Concept | Stored under | Role |
|---|---|---|
| Member classes | `member.classes` | Field labels, mandatory flags, constraints |
| Member types | `member.types` | Purchasable membership products and prices |

## Configuration routes

- `conreg_config_member_classes`
- `conreg_config_member_types`

## Pipe-code warning

For code+label lists (for example badge/day options), keep the code segment
before `|` stable so references remain valid across config and data.

## Registration display

On the Registration form, member types render as selectable cards (the
`member_type_cards` form element, `src/Element/MemberTypeCards.php`), not a
`select` dropdown — each card shows the type's name, price, and description.

- The type description field is `text_format` (rich text, stored with a
  `descriptionFormat`), not plain text — HTML in the description renders on
  the card.
- Types not allowed for the first member (`allowFirst` off) are still shown
  to member 1, just disabled with a "Not available for Member 1" message —
  they aren't hidden the way the old dropdown hid them.
- When the selected type has per-day pricing, its day options render inside
  the card, each showing its price alongside its description (e.g. `[ ] €25
  Saturday`), styled to match the card's own price/description.
- See `docs/development/theming.md` for how to customize a specific type's
  card appearance, or a specific day's option.

## Preselecting a member type via URL

The registration form (`conreg_register` and its fan-table/portal variants) accepts an optional
`type` query parameter, e.g. `members/register/1?type=A`. When present and set to a valid member
type code (the key under `member.types` for the event, e.g. `A`), that type's card is preselected
on page load for the first member only, overriding the configured `member_type_default` for that
member. An unrecognized or invalid code is silently ignored: the first member falls back to
`member_type_default` if one is configured, or otherwise loads with no type preselected. Any
additional members on the form are unaffected by the query parameter - they always use
`member_type_default` (if configured), never the URL value.
