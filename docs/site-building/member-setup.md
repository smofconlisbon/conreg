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
