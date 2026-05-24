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

- `conreg_clickup.links.menu.yml` references
  `\Drupal\conreg_clickup\Plugin\Derivative\ClickUpMenuDeriver`, but this class
  is not present in the repository.
- `conreg_clickup.links.task.yml` declares task key
  `conreg_clickup.config_planz_options` and points to
  `conreg_config_planz_options`.
