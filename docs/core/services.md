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
| `conreg.print_job.manager` | `PrintJobManager` | Creates/loads badge-label print jobs and printers — see `site-building/label-printing.md` |
| `conreg.pricing` | `PricingService` | Registration pricing via `MemberPricingRule`/`PricingAdjustment` plugins — see `docs/architecture/pricing.md` |
| `conreg.pricing.member_rule_manager` | `MemberPricingRulePluginManager` | Discovers `MemberPricingRule` plugins |
| `conreg.pricing.adjustment_manager` | `PricingAdjustmentPluginManager` | Discovers `PricingAdjustment` plugins |
| `conreg.rate_plan.manager` | `RatePlanManager` | Rate plan rules (missing member type and day prices, applying a plan) and display formatting; rate plans themselves are `conreg_rate_plan` entities |

## Usage guidance

- Constructor injection is available (`autowire: true`, `autoconfigure: true`).
- Legacy/static access (`\Drupal::service(...)`) still appears in code.
