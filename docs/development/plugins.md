# Plugins

Alongside the hook-based extension seam (`docs/development/hooks.md`), ConReg
uses Drupal's plugin system for extension points where multiple
implementations need to be discovered, selected, and ordered — rather than
every subscriber just reacting independently. All ConReg plugin types follow
the same shape:

- A plain interface declaring the contract.
- A `#[\Attribute(\Attribute::TARGET_CLASS)]` attribute class (not old-style
  annotations) used to tag implementations.
- A manager extending `Drupal\Core\Plugin\DefaultPluginManager`, discovering
  implementations under `Plugin/<Type>/` in any module.
- Registration in the owning module's `*.services.yml` with
  `parent: default_plugin_manager`, which supplies the standard
  cache/discovery wiring.

| Plugin type | Manager service | Purpose |
|---|---|---|
| `MemberPricingRule` | `conreg.pricing.member_rule_manager` | Prices one member in isolation |
| `PricingAdjustment` | `conreg.pricing.adjustment_manager` | Adjusts prices across a whole registration |
| `MailingListProvider` | `conreg.mailing_list.provider` | Subscribes members to a third-party mailing list |

## MemberPricingRule

Namespace `Drupal\conreg\Pricing`, attribute
`Drupal\conreg\Pricing\Attribute\MemberPricingRule`, concrete plugins under
`src/Plugin/MemberPricingRule/`.

```php
public function label(): string;
public function priceMember(PricingSubject $subject, PricingContext $context): MemberPriceContribution;
```

Shipped: `BaseTypePricingRule` (type + partial-day pricing), `AddonPricingRule`
(a member's own add-on selections). See `docs/architecture/pricing.md` for
how these fit into the wider pricing calculation.

## PricingAdjustment

Namespace `Drupal\conreg\Pricing`, attribute
`Drupal\conreg\Pricing\Attribute\PricingAdjustment`, concrete plugins under
`src/Plugin/PricingAdjustment/`.

```php
public function label(): string;
public function adjust(array $memberResults, PricingContext $context): array; // PriceAdjustment[]
```

Shipped: `NthMemberFreeAdjustment` (the "every Nth member free" discount).
See `docs/architecture/pricing.md`.

Both pricing plugin types carry a `weight` on their attribute and run in
ascending order; a `PricingAdjustment` sees the effect of any
earlier-weighted adjustment, so they compose rather than each starting from
the unadjusted price.

## MailingListProvider

*(Not yet documented here — see `docs/submodules/mailing-list.md` for the
current provider contract and shipped/expected providers. Consolidating that
into this shared reference is tracked as a follow-up.)*
