# Caching

## Patterns in use

| Pattern | Usage |
|---|---|
| Data cache bins | `ConregOptions` caches computed class/type objects |
| Cache tags | Member updates invalidate event/member-related tags |
| Route options | Some routes use `no_cache: true` (checkout/login/portal variants) |

## Why it matters

- Member class/type options are recalculated from config and cached.
- Save/delete operations trigger invalidation to keep list/report data current.

## Explicit non-use

- No queue worker processing in current architecture.
