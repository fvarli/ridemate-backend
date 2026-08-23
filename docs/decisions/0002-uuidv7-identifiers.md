# 2. UUIDv7 as the public identifier

**Status:** accepted (Phase 8), unimplemented — no table exists yet.

## Context

Identifiers appear in URLs a mobile client holds, in logs, and in every foreign key. The
choice is expensive to reverse once data exists.

## Decision

**UUIDv7 stored in native PostgreSQL `uuid` columns**, as the single public strategy.

* Time-ordered, so inserts stay local in the B-tree instead of fragmenting it the way
  random UUIDv4 does.
* Sixteen bytes in a native column, not a string.
* First-party in Laravel via `HasVersion7Uuids`.
* Not enumerable, unlike a sequential integer exposed in a URL.

No dual integer-plus-UUID keys: the second key adds a join and a synchronisation concern
to buy locality that v7 already provides.

ULID was the runner-up. It loses only on the native column type, and that is enough.

## Consequences

* Applied to the first table in Phase 9; nothing implements it now, and no dummy model was
  created to demonstrate it.
* Request ids are deliberately **not** domain identifiers, even though both are UUIDs. The
  `X-Request-Id` header keeps the concepts apart.
