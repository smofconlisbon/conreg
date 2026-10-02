# Member Classes and Types

## Classes vs types

| Concept | Stored under | Role |
|---|---|---|
| Member classes | `member.classes` | Field labels, mandatory flags, constraints |
| Member types | `member.types` | Purchasable membership products and prices |

## Configuration routes

- `conreg_config_member_classes`
- `conreg_config_member_types`
- `entity.conreg_rate_plan.collection` (rate plans)

## Rate plans

A rate plan sets the price of every member type, and of each day enabled for
it, on a planned date, for example an early bird deadline. Plans are listed
under the Rate Plans tab of the event configuration, ordered by planned date,
with overdue plans highlighted. Each plan's price changes are shown as if the
plans before it have been applied.

Plans are not applied automatically: an administrator applies each plan,
after reviewing how each price will change. A plan must give every member type
a price before it can be saved. If member types are added, or days enabled,
after a plan was saved, the plan can't be applied until it is edited to give
them a price.
A day with no current price is free, so a new plan gives it a price of 0.
A plan that wouldn't change any of the current prices can't be applied.
Plans can be applied in any order, but applying a plan warns if there are
unapplied plans planned before it.
Deleted member types and disabled days aren't shown on a plan, and their
prices are removed from it the next time it is saved or applied.

Applying a plan updates the main price of each member type and the price of
each of its enabled days. Plans never enable or disable days, or change their
descriptions. Applied plans cannot be edited or deleted, and are kept as a
history of price changes, including the price each member type and day had
before. The history shows member types and days by the names they had when the
plan was applied, including member types deleted and days disabled since.
Prices changed directly on the Member Types tab aren't recorded, so the
applied rate plans list isn't a complete price history.
Planned dates are in the site's default timezone.

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
