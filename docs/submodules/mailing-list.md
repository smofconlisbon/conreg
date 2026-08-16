<! -- cspell:ignore autowires testprovider -->

# Mailing List (`testprovider`)

## What it adds

| Area | Details |
|---|---|
| Config entity | `conreg_subscription_rule` (`ConregSubscriptionRule`) — per-event rule matching members to a provider list |
| Plugin type | `MailingListProvider` (attribute `Drupal\conreg_mailing_list\Attribute\MailingListProvider`, manager `MailingListProviderPluginManager`) |
| Queue worker | `MailingListSubscriptionWorker` (plugin/queue ID `conreg_mailing_list_subscription`) |
| Routes | `entity.conreg_subscription_rule.{collection,add_form,edit_form,delete_form,sync}`, all under `/admin/config/conreg/{eid}/subscription-rule` |
| Permission | `administer conreg_subscription_rule` |

This is the framework other `conreg_*` mailing-list integrations (`conreg_mailerlite`,
`conreg_simplenews`) plug into — it has no mailing list provider of its own besides the
bundled `testprovider` used for development/tests.

## Provider contract

`MailingListProviderInterface`:

```php
public function label(): string;
public function getLists(): array;   // provider list ID => human label
public function subscribe(string $email, string $listId, array $fields): void;
```

Provider plugins throw `MailingListException` (or its `MailingListTransientException`/
`MailingListPermanentException` subclasses) rather than letting arbitrary exceptions
escape — the add/edit form catches `MailingListException` from `getLists()` and degrades
gracefully; the queue worker catches the transient/permanent split from `subscribe()` to
decide whether to retry.

## Hook integration

`ConregMailingListHooks::memberAdded()` (`#[Hook('convention_member_added')]`)
runs whenever a new member is saved. For each `ConregSubscriptionRule` belonging
to that event, it checks `$rule->matches($member)` (communication method **and**
member option constraints, when set — both must hold) and, if the member's email
is valid and the rule matches, enqueues a `conreg_mailing_list_subscription`
queue item. `MailingListSubscriptionWorker` processes the queue asynchronously,
resolving the rule's `provider`/`list_id` to the matching plugin and calling
`subscribe()`.

This only ever fires on `convention_member_added` — members who registered *before* a
rule existed are never retroactively matched. `entity.conreg_subscription_rule.sync`
(the "Sync now" operation on the rule list) backfills this: `MailingListSyncService::syncRule()`
loads every member of the rule's event and runs the same `enqueueIfMatches()` check
(shared with the hook, extracted onto `ConregMailingListHooks`) against each one. It's
subscribe-only, deliberately — nothing tracks which rule is responsible for which
subscription, so unsubscribing members who no longer match isn't safe to automate yet.

## Implementation notes

- `MailingListProviderInterface` methods take no event ID — providers are assumed to be
  single, global instances (one MailerLite account, one Simplenews install) shared across
  all events. Which list a member gets subscribed to is entirely determined by the
  `ConregSubscriptionRule` (`eid` + `list_id`), not by the provider.
- The queue name is the worker's own plugin ID
  (`MailingListSubscriptionWorker::QUEUE_NAME`) — always enqueue against that constant
  rather than a literal string, so the two can't drift apart.
- `MailingListProviderPluginBase` autowires a `logger.channel.conreg_mailing_list`
  logger via `#[Autowire]`. Provider plugins needing extra services (config, HTTP client,
  entity storage, etc.) can add their own constructor parameters the same way — see
  `conreg_mailerlite`/`conreg_simplenews` — without needing to write a `create()` method,
  as long as each extra dependency's interface either has a container-wide FQCN service
  alias or is tagged with an explicit `#[Autowire(service: '...')]`.
