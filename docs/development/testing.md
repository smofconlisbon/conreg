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

## `--group` filtering pitfall

A PHPUnit test class carrying `#[RunTestsInSeparateProcesses]` alongside
a docblock `@group conreg` annotation (rather than the `#[Group('conreg')]`
attribute) is silently excluded by `--group conreg` filtering - it still
runs and passes when the test file/directory is targeted directly, so
this is easy to miss. Use the `#[Group('conreg')]` attribute (see
`tests/src/Kernel/FormBuildTest.php`) on any new test class that also
uses `#[RunTestsInSeparateProcesses]`.

## CI context

`.gitlab-ci.yml` includes DrupalCI templates and variable settings.

## Suggested strategy

For this codebase shape, prioritize functional behavior tests around routes and
business flows as refactors proceed.
