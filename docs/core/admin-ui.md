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

## Local tasks

Core tabs in `conreg.links.task.yml`:

- Event configuration
- Member classes
- Member types
- Add-ons
