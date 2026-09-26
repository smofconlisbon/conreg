# ClickUp (`conreg_clickup`)

## What it adds

| Area | Details |
|---|---|
| Routes | `conreg_config_clickup`, `conreg_config_clickup_options` |
| Forms | `ConregConfigClickUpForm`, `ConregConfigClickUpOptionsForm` |
| Permission | `configure ClickUp integration` |
| Hook | `conreg_clickup_convention_member_updated()` |

## Data interaction

- Uses core table `conreg_member_clickup_options` (declared by parent schema).
- Task creation/update is implemented in `ConregClickUp`.

## Implementation notes

- The module is not currently in use and has no admin menu links. Its menu
  file referenced a `ClickUpMenuDeriver` class that never existed, which broke
  menu rebuilds, so it was removed until the module is reworked. The previous
  static links can be recovered with
  `git show defe100^:conreg_clickup/conreg_clickup.links.menu.yml`.
