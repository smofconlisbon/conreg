# Routes Reference

## Core route groups

| Group | Examples |
|---|---|
| Public/member | `conreg_register`, `conreg_checkout`, `conreg_checkin_checkout`, `conreg_thanks`, `conreg_list`, `conreg_check`, `conreg_login`, `conreg_portal`, `conreg_portal_edit` |
| Context variants | `conreg_fantable_register`, `conreg_fantable_checkout`, `conreg_portal_register`, `conreg_portal_checkout` |
| Event config (per event) | `conreg_overview`, `conreg_event_list`, `conreg_event_overview`, `conreg_event_clone`, `conreg_config`, `conreg_config_member_classes`, `conreg_config_member_types`, `conreg_config_addons` |
| Member admin | `conreg_admin_members*`, `conreg_admin_checkin`, `conreg_admin_fantable` |
| Reports/email | `conreg_admin_member_list`, `conreg_admin_member_options`, `conreg_admin_member_addons`, `conreg_admin_mailout_*`, `conreg_admin_member_summary`, `conreg_admin_child_member_ages`, `conreg_admin_bulk_email*` |
| Badge upload | `conreg_badge_upload` |
| Print job API | `conreg.print_job_next` (`GET /api/print-jobs/{eid}/next`), `conreg.print_job_result` (`POST /api/print-jobs/{eid}/{id}/result`) - shared-key authenticated, not Drupal-permission gated; see `site-building/label-printing.md` |

## Submodule routes

| Module | Routes |
|---|---|
| `conreg_airtable` | `conreg_config_airtable_options` |
| `conreg_badges` | `conreg_badges_list`, `conreg_badges_list_export`, `conreg_badges_print` |
| `conreg_clickup` | `conreg_config_clickup`, `conreg_config_clickup_options` |
| `conreg_discord` | `conreg_config_discord_invitebot` |
| `conreg_lookup` | `conreg_member_lookup` |
| `conreg_planz` | `conreg_config_planz_options`, `conreg_config_planz_admin` |
| `conreg_mailing_list` | `entity.conreg_subscription_rule.{collection,add_form,edit_form,delete_form}`, all under `/admin/config/conreg/{eid}/subscription-rule` |
| `conreg_mailerlite` | `conreg_config_mailerlite` (`/admin/config/conreg/mailerlite`) |
| `easy_email` (contrib) | `entity.easy_email_type.collection` (`/admin/structure/email-templates`) - manage templates; `entity.easy_email.collection` (`/admin/content/email`) - sent-email log |
