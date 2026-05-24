# Stripe and Payments

## Config keys

Payment behavior is primarily driven by `payments.*` keys in
`conreg.settings.{eid}`:

- `system`, `mode`, `public_key`, `private_key`
- `currency`, `symbol`
- `auto_approve`
- `show_remaining`

## Runtime tables

| Table | Role |
|---|---|
| `conreg_payments` | Payment header/status |
| `conreg_payment_sessions` | Stripe session state |
| `conreg_payment_lines` | Line-level amounts and members |

## UI flow

- Registration submits members and payment records.
- Checkout route processes payment session and completion.
