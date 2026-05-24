# Testing

## Current tests in repository

| Location | Scope |
|---|---|
| `tests/src/Kernel/` | Kernel test coverage for selected forms/controllers |
| `conreg_badges/tests/src/Kernel/` | Badges kernel tests |

## CI context

`.gitlab-ci.yml` includes DrupalCI templates and variable settings.

## Suggested strategy

For this codebase shape, prioritize functional behavior tests around routes and
business flows as refactors proceed.
