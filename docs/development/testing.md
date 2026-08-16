# Testing

## Current tests in repository

| Location | Scope |
|---|---|
| `tests/src/Kernel/` | Kernel test coverage for selected forms/controllers |
| `conreg_badges/tests/src/Kernel/` | Badges kernel tests |
| `conreg_mailing_list/tests/src/Kernel/` | `ConregSubscriptionRule` CRUD/form/routing, plugin manager, queue worker, hook-to-queue pipeline |
| `conreg_mailing_list/tests/src/Unit/` | `ConregSubscriptionRule::matches()` business logic |
| `conreg_mailing_list/tests/modules/conreg_mailing_list_test/` | Test-support module: `FakeProvider`/`FakeProviderCallRecorder` for controllable provider behavior in tests |
| `conreg_mailerlite/tests/src/Unit/` | `MailerliteProvider` request/response handling and failure mapping (mocked HTTP, no Drupal bootstrap) |
| `conreg_simplenews/tests/src/Kernel/` | `SimplenewsProvider` against real Simplenews entities |

## CI context

`.gitlab-ci.yml` includes DrupalCI templates and variable settings.

## Suggested strategy

For this codebase shape, prioritize functional behavior tests around routes and
business flows as refactors proceed.
