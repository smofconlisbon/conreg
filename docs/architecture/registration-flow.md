# Registration and Payments

## Main routes

| Step | Route |
|---|---|
| Register | `conreg_register` (`members/register/{eid}`) |
| Checkout | `conreg_checkout` (`members/checkout/{payid}/{key}`) |
| Thank you | `conreg_thanks` (`members/thanks/{eid}`) |

Variants exist for fan table and portal flows using a `return` context.

## Sequence

```mermaid
sequenceDiagram
  participant U as User
  participant R as Registration form
  participant M as Member save
  participant C as Checkout form
  participant S as Stripe session
  participant T as Thank-you route
  U->>R: Submit members
  R->>M: Save members and payment rows
  U->>C: Open checkout link
  C->>S: Create/use payment session
  S-->>C: Session completed
  C->>M: Mark payment/member state
  C->>T: Redirect
```

## Implementation notes

- Zero-balance checkout completes without a Stripe redirect.
- Auto-approval behavior is config driven (`payments.auto_approve`).
