# Simplenews Linking

## Where integration runs

Simplenews integration is the `simplenews` `MailingListProvider` plugin, provided by
`conreg_simplenews`. There is no integration code in the parent `conreg` module.

## Builder flow

1. Enable both `simplenews` and `conreg_simplenews`.
2. Create a `ConregSubscriptionRule` for the event (`conreg_mailing_list`'s admin UI),
   picking `Simplenews` as the provider and the target newsletter as the list.
3. Optionally constrain the rule by communications method and/or member option, the
   same as for any other provider.
4. If members already registered before the rule existed, use the rule's "Sync now"
   operation to enqueue everyone who currently matches — new registrations are picked up
   automatically, but existing ones aren't backfilled on their own.

## Behavior summary

When a member is added and matches an active rule, `MailingListSubscriptionWorker`
queues and subscribes them to the selected Simplenews newsletter via
`simplenews.subscription_manager`. There's no synchronous subscribe/unsubscribe logic in
the registration form — subscription is driven entirely by rule matching and processed
asynchronously through the queue.
