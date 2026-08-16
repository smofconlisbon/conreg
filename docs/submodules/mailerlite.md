<! -- cspell:ignore upserting -->

# MailerLite (`conreg_mailerlite`)

## What it adds

| Area | Details |
|---|---|
| Provider plugin | `MailerliteProvider` (`Plugin\MailingListProvider`, ID `mailerlite`) |
| Config | `conreg_mailerlite.settings` — single `api_key`, shared by all events |
| Config route | `conreg_config_mailerlite` (`/admin/config/conreg/mailerlite`) |
| Config form | `Drupal\conreg_mailerlite\Form\ConfigMailerliteForm` |
| Permission | `configure mailerlite integration` |
| Menu link | `conreg_mailerlite.config`, under `conreg.overview` |
| Dependency | `key` — the API key is stored as a Key module reference, not plain text |

Implements `conreg_mailing_list`'s `MailingListProviderInterface` against MailerLite's
"connect" API (`https://connect.mailerlite.com/api`) via Guzzle.

## Data interaction

- `getLists()` calls `GET /groups` and maps the response to `id => name`.
- `subscribe()` calls `POST /subscribers`, upserting by email and assigning the group;
  MailerLite won't resubscribe an address it records as unsubscribed.
- Both go through a shared `request()` helper that resolves the API key (see below) and
  maps failures: no key configured, key not resolvable, a `ConnectException`, a `429`,
  or a `5xx` → `MailingListTransientException` (retryable); any other non-2xx →
  `MailingListPermanentException`.

## Credentials via the Key module

`conreg_mailerlite.settings:api_key` stores a **Key entity ID**, not the secret itself.
`MailerliteProvider` resolves the actual value at request time:
`$this->keyRepository->getKey($keyId)?->getKeyValue()`. A missing key ID, a Key ID that
doesn't resolve to an entity, or a Key whose provider fails to produce a value are all
treated as the same transient "not configured" failure — all three are admin-fixable, so
none of them should ever cause a *permanent* failure/drop.

`ConfigMailerliteForm`'s `api_key` field is `#type => 'key_select'` (filtered to
`type_group: 'authentication'` keys), not a plain textfield. The form also shows a live
"Key status" line whenever a key is configured — it calls the real `mailerlite`
provider's `getLists()` through the plugin manager and reports either
`Valid - N mailing lists found.` or `Invalid key.`. This is informational only; it
doesn't block saving an untested or currently-invalid key.

## Implementation notes

- Credentials are **global**, not per-event — this instance is always run by one
  organization, so one API key covers every event. (A per-event credentials model was
  considered and rejected; see `docs/submodules/mailing-list.md`.)
- `MailerliteProvider`'s constructor is fully autowired — `ClientInterface` and
  `ConfigFactoryInterface` resolve by type alone (core FQCN service aliases);
  `LoggerInterface` and `KeyRepositoryInterface` both need an explicit
  `#[Autowire(service: '...')]` since neither has an FQCN alias — no `create()` method
  needed either way.
- `ConfigMailerliteForm` itself is autowired the same way (`FormBase`'s `AutowireTrait`,
  not the plugin-specific mechanism) — its constructor forwards `ConfigFactoryInterface`/
  `TypedConfigManagerInterface` to `parent::__construct()` and adds
  `MailingListProviderPluginManager` (also FQCN-aliased) for the key-status check.
