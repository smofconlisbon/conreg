# Core Features

## Public/member-facing

- Registration (`Registration` form) with multi-member groups.
- Checkout (`Checkout`) with paid and free completion paths.
- Membership check (`CheckMember`) and login link flow.
- Member portal (`MemberPortal`) and member self-edit (`MemberEdit`).
- Public member list (`ConregController::memberList`).

## Admin-facing

- Event list and clone (`EventList`, `EventClone`).
- Event config, member classes, member types, add-ons, email templates.
- Member admin (`AdminMembers`) with add/edit/delete/transfer/email.
- Check-in, fan table registration, options/add-ons reports.
- Mailout export and bulk email sender.

## Extension-facing

- Hook lifecycle events for member add/update/delete.
- Dynamic permissions for event option fields.
