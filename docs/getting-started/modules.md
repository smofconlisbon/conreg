# Module Enablement

## Module inventory

| Extension | Purpose | Declared dependencies |
|---|---|---|
| `conreg` | Core convention registration workflows | `key` |
| `conreg_airtable` | Sync member data to Airtable | `conreg` |
| `conreg_badges` | Badge list/export/print flows | `conreg` |
| `conreg_clickup` | Create ClickUp tasks from options | `conreg` |
| `conreg_discord` | Generate Discord invites and emails | `conreg` |
| `conreg_lookup` | Staff lookup of member records | `conreg` |
| `conreg_planz` | PlanZ/Zambia integration | `conreg` |
| `conreg_mailing_list` | Subscription-rule framework and `MailingListProvider` plugin type | `conreg` |
| `conreg_mailerlite` | `MailingListProvider` plugin for MailerLite | `conreg`, `conreg_mailing_list` |
| `conreg_simplenews` | `MailingListProvider` plugin for Simplenews | `conreg`, `conreg_mailing_list`, `simplenews` |

## Enable order

1. Enable `conreg`.
2. Enable supporting submodules — `conreg_mailing_list` before
   `conreg_mailerlite`/`conreg_simplenews`.
3. Assign required permissions for each enabled module.

## Important implementation note

Each mailing-list provider is a self-contained plugin: `conreg_mailerlite`'s logic lives
in `conreg_mailerlite/src/Plugin/MailingListProvider/MailerliteProvider.php`,
`conreg_simplenews`'s in
`conreg_simplenews/src/Plugin/MailingListProvider/SimplenewsProvider.php`. Neither adds
anything to the parent `conreg` module — `conreg_mailing_list` owns the shared
subscription-rule/queue framework they plug into.
