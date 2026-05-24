# Core Admin UI

## Static admin links

`conreg.links.menu.yml` provides:

- `conreg.events` (`admin/config/conreg/events`)
- `conreg.config_email` (`admin/config/conreg/email/templates`)

## Dynamic event menu tree

`EventsMenuDeriver` adds per-event links under `conreg.events`, including:

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
