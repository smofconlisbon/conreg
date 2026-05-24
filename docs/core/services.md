# Core Services

## Service definitions (`conreg.services.yml`)

| Service ID | Class | Responsibility |
|---|---|---|
| `conreg.country` | `CountryService` | Country option support |
| `conreg.stripe_service` | `StripeService` | Stripe interactions and payment helpers |
| `conreg.member.storage` | `MemberStorage` | Member table reads/writes and admin queries |
| `conreg.event.storage` | `EventStorage` | Event table access |
| `conreg.addon_storage` | `AddonStorage` | Add-on table access |
| `conreg.upgrade.storage` | `UpgradeStorage` | Upgrade table access and upgrade lookups |

## Usage guidance

- Constructor injection is available (`autowire: true`, `autoconfigure: true`).
- Legacy/static access (`\Drupal::service(...)`) still appears in code.
