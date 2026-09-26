# Core Admin UI

## Static admin links

`conreg.links.menu.yml` provides:

- `conreg.overview` (`admin/config/conreg/overview`)
- `conreg.events` (`admin/config/conreg/events`) under `conreg.overview`

Email templates are managed on Easy Email's own admin page, not a ConReg
route - see `entity.easy_email_type.collection`
(`/admin/structure/email-templates`) in `reference/routes.md`.

## Dynamic event menu tree

`EventsMenuDeriver` adds per-event links under `conreg.overview`, including:

- Member summary
- Administer members
- List all member details
- Export email mailing list
- Selected options, add-ons, child members
- Fan table, check-in, bulk email
- Configure registration

Each event gets a parent link, `conreg.event_links:conreg_event_{eid}`, under
`conreg.overview`, and the links above are its children. Submodules (badges,
PlanZ, lookup, Discord) add their own per-event links by using the same
parent ID in their derivers.

Derivers only run when the menu link tree is rebuilt, so `EventStorage`
rebuilds it after inserting or deleting an event, and after an update that
changes a field listed in `EventStorage::MENU_FIELDS` (currently
`event_name`). Code that writes to `conreg_events` directly, bypassing
`EventStorage`, won't update the menu until the next cache clear.

## Local tasks

Core tabs in `conreg.links.task.yml`:

- Event configuration
- Member classes
- Member types
- Add-ons
