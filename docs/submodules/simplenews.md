# Simplenews (`conreg_simplenews`)

## Module shape

`conreg_simplenews` provides a `MailingListProvider` plugin for the
`conreg_mailing_list` framework — the same pattern `conreg_mailerlite` uses for
MailerLite.

## Actual implementation location

All Simplenews behavior lives in
`conreg_simplenews/src/Plugin/MailingListProvider/SimplenewsProvider.php`:

- `getLists()` returns every `simplenews_newsletter` config entity as `id => label`.
- `subscribe()` calls Simplenews's own `simplenews.subscription_manager` service.

There is no `hook_form_alter()`, no admin form, and no bespoke config storage — matching
a mailing list to an event and a communications method is handled entirely by
`conreg_mailing_list`'s `ConregSubscriptionRule` entity, the same as for any other
provider.

## Config footprint

None. `conreg_simplenews` owns no config of its own; Simplenews's own newsletters and
subscribers are queried/updated directly through its entity API.
