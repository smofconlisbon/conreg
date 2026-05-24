# Extending ConReg

## Extension approach

The core extension seam is member lifecycle hooks fired from `Member` save and
delete operations.

## Example hook skeleton

```php
<?php

use Drupal\conreg\Member;

function mymodule_convention_member_updated(Member $member): void {
  // Custom integration logic.
}
```

## Design notes

- Prefer service injection in new code.
- Preserve hook argument type expectations used by existing submodules.
