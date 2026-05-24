# Core Public Routes

## Public and member routes

| Route | Path | Handler | Permission |
|---|---|---|---|
| `conreg_register` | `members/register/{eid}` | `Form\Registration` | `convention registration` |
| `conreg_fantable_register` | `admin/members/fantable/register/{eid}` | `Form\Registration` | `fan table registration` |
| `conreg_portal_register` | `members/portal/register/{eid}` | `Form\Registration` | `member portal` |
| `conreg_checkout` | `members/checkout/{payid}/{key}` | `Form\Checkout` | `convention registration` |
| `conreg_fantable_checkout` | `members/fantable/checkout/{payid}/{key}` | `Form\Checkout` | `convention registration` |
| `conreg_checkin_checkout` | `members/checkin/checkout/{payid}/{key}` | `Form\Checkout` | `convention registration` |
| `conreg_portal_checkout` | `members/portal/checkout/{payid}/{key}` | `Form\Checkout` | `convention registration` |
| `conreg_thanks` | `members/thanks/{eid}` | `ConregController::registrationThanks` | `convention registration` |
| `conreg_list` | `members/list/{eid}` | `ConregController::memberList` | `view public members` |
| `conreg_check` | `members/check/{eid}` | `Form\CheckMember` | `check membership` |
| `conreg_login` | `members/login/{mid}/{key}/{expiry}` | `LoginController::memberLoginAndRedirect` | `_access: TRUE` |
| `conreg_portal` | `members/portal/{eid}` | `Form\MemberPortal` | `member portal` |
| `conreg_portal_edit` | `members/portal/edit/{eid}/{mid}` | `Form\MemberEdit` | `member portal` |
| `conreg_badge_upload` | `members/badge/upload/{eid}` | `BadgeUploadController::badgeUpload` | `view membership badges` |

## Context-specific variants

- `conreg_fantable_*` routes support fan table operator workflows.
- `conreg_portal_*` routes keep member portal users in portal context.
- `conreg_checkin_checkout` supports check-in assisted checkout flow.
