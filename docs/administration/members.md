# Member Management

## Primary routes

| Route | Purpose |
|---|---|
| `conreg_admin_members` | Main queue/list management |
| `conreg_admin_members_add` | Add member |
| `conreg_admin_members_edit` | Edit member |
| `conreg_admin_members_delete` | Delete member |
| `conreg_admin_members_transfer` | Transfer member group |
| `conreg_admin_members_email` | Email member |

## Typical operations

- Review approval/payment queues.
- Assign member numbers as part of approval flow.
- Edit profile and membership attributes.
- Transfer members between leads when needed.
- Email a member (`conreg_admin_members_email`, `MemberEmail` form): pick any
  `EasyEmailType`, edit the subject/body before sending, and see a live,
  debounced preview with `[conreg:*]` tokens resolved. The sent email is
  logged in Easy Email's own log (`/admin/content/email`) - see
  `architecture/email.md`.
