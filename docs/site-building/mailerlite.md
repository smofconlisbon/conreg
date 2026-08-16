# MailerLite Linking

## Where integration runs

MailerLite integration is the `mailerlite` `MailingListProvider` plugin, provided by
`conreg_mailerlite`. There is no per-event MailerLite configuration — one API key,
configured once, is shared by every event on the site.

## Builder flow

1. Enable `conreg_mailerlite` (this also enables the `key` module dependency).
2. Get an API token from MailerLite (Integrations → API). Create a Key
   (`/admin/config/system/keys/add`) to hold it — any key type in the "Authentication"
   group works; the "Configuration" provider is the simplest for a single value.
3. There are several ways of storing the key on the site, but it is not
   recommended to store it in the configuration, as this can be exported and
   should be considered public. The simplest option is to store in a file
   (somewhere outside the webroot).
4. At **Configuration  System  Keys** (`/admin/config/system/keys`, requires
   `Administer keys` permission), create a new key. Give it a name, set type to
   authentication, set key provider to file, and enter the path to the file
   location containing the key. Save the key.
5. At **Configuration → ConReg → MailerLite Integration**
   (`/admin/config/conreg/mailerlite`, requires the `configure mailerlite
   integration` permission), select that Key from the "Mailerlite API key" field
   and save. Once a key is selected, the page shows a live "Key status" line —
   "Valid - N mailing lists found." or "Invalid key." — confirming the key
   actually works before you rely on it.
6. Create a `ConregSubscriptionRule` for the event (`conreg_mailing_list`'s admin UI),
   picking `MailerLite` as the provider and the target group as the list.
7. Optionally constrain the rule by communications method and/or member option, the same
   as for any other provider.
8. If members already registered before the rule existed, use the rule's "Sync now"
   operation to enqueue everyone who currently matches — new registrations are picked up
   automatically, but existing ones aren't backfilled on their own.

## Behavior summary

When a member is added and matches an active rule, `MailingListSubscriptionWorker`
queues and subscribes them to the selected MailerLite group. If the API key is missing
or MailerLite is temporarily unreachable (timeout, rate limit, 5xx), the attempt is
treated as retryable and the queue tries again later; a rejected/invalid address is
dropped without retrying. There's no synchronous API call anywhere in the registration
or admin form flow — subscription is driven entirely by rule matching and processed
asynchronously through the queue.
