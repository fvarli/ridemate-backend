# RideMate API — Conventions

The rules every endpoint follows. `openapi/openapi.yaml` is the contract; this explains
the decisions behind it.

## Versioning

`/api/v1`, versioned in the path. Operational endpoints (`/health`, `/ready`) sit
**outside** it deliberately: they are not part of the product API and an orchestrator
probing them should never have to follow an API version bump.

## Shape

**There is no success envelope.** A resource returns its own JSON and HTTP status codes do
their job. Wrapping every payload in `{"data": …}` costs a key on every response and every
client parse in exchange for nothing until something genuinely needs to travel alongside
the payload. When that arrives it is added then, for that reason.

| Concern | Convention |
|---|---|
| Field naming | `snake_case` |
| Identifiers | UUIDv7 strings |
| Timestamps | RFC 3339 UTC with `Z`, second precision — `2026-08-23T09:41:00Z` |
| Booleans | Named positively — `is_verified`, never `not_verified` |
| Absent values | `null`, never an empty string or a zero standing in for "unknown" |

## Errors

One shape, always:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": { "phone": ["The phone field is required."] },
    "request_id": "0199c4f2-7a1b-7c3d-8e4f-5a6b7c8d9e0f"
  }
}
```

**`code` is the contract.** A stable machine string clients map to their own localized
copy. Renaming one is a breaking change.

**`message` is developer-facing English and clients must never display it.** The Flutter
client owns its approved localization keys with Turkish as the source language; if the
server sent display text, message ownership would fork across two repositories with two
release cadences and the app would eventually show strings its translators never approved.

`details` appears only for validation, so its presence is meaningful. `request_id` always
matches the `X-Request-Id` response header.

| Status | Code | When |
|---|---|---|
| 400 | `bad_request` | Malformed request |
| 401 | `unauthenticated` | No or invalid credentials |
| 403 | `forbidden` | Authenticated but not permitted |
| 404 | `not_found` | No such resource, or not visible to this member |
| 405 | `method_not_allowed` | Wrong verb for a known path |
| 409 | `conflict` | The resource is not in the expected state |
| 422 | `validation_failed` | Well-formed but invalid |
| 429 | `rate_limited` | Too many requests |
| 500 | `internal_error` | Unexpected failure |

A production `500` carries a code and a request id and **nothing else** — no exception
text, stack frame, file path, SQL or configuration. Those can contain a hostname or a
credential and a client can act on none of it; the log line for that request has the
detail.

`404` rather than `403` for a resource the caller may not see: distinguishing them tells
an attacker the resource exists.

## Request correlation

Every response carries `X-Request-Id`. An inbound one is honoured **only if it is a
well-formed UUID**, so a caller can correlate a retry; anything else is replaced. The id
reaches the log stream, so accepting arbitrary client input would let anyone forge or
pollute log lines, and an unbounded value would be a denial of service on log volume.

## Pagination — cursor, never offset

Cursor-based: `?cursor=…&limit=…`, with `next_cursor` in the response.

Offset pagination over a feed that mutates — journeys filling up, requests being accepted —
shows duplicates and silently skips items as rows shift between pages. That is a
correctness problem, not a performance one, and it is invisible in testing against static
data.

**Implemented in Phase 10** by `GET /api/v1/me/routes`, the first list endpoint, and the
shape every later one follows:

* **`next_cursor: null` is the only end-of-list signal.** An empty `routes` array is not —
  a page can legitimately come back empty with rows behind it, and a client that stopped
  there would hide a member's own data from them with nothing to notice.
* **The cursor is opaque.** It is produced by the endpoint and passed back unchanged. It is
  not a date, an id, an offset or a page number, and a client must never construct, parse or
  modify one — what it encodes has to stay free to change. Opaque here means *encrypted*,
  not merely encoded: a base64 of the sort key would make the word false the moment somebody
  looked.
* **A cursor is a position, not a capability.** It carries no account id, and the query it
  resumes is re-authorized on every request — scoped to the caller where the feed is theirs,
  and filtered for eligibility where it is public — so presenting someone else's cursor
  grants nothing.
* **The version tag names the surface, not just the format.** Two feeds that order by the
  same tuple would otherwise accept each other's cursors and resume from a position
  established somewhere else. A cursor from the wrong surface is refused rather than decoded.
* An unusable cursor — tampered, wrong version, wrong surface, wrong shape — is
  `422 validation_failed` on the `cursor` field, never a `500`, and the message says only
  that it is not usable.
  Describing *why* would describe the format.
* `limit` is bounded by the contract, with a documented default, and the server may return
  fewer than asked.

## Idempotency — three mechanisms, one chosen per command

Mobile retries are not hypothetical. "The passenger sent two requests for the last seat
because the tunnel dropped" is a support incident on day one, and the fix has to exist
before the endpoint does.

Phase 8 answered that with a single blanket rule: every non-GET would carry an
`Idempotency-Key`, replayed for 24 hours. Phase 10 replaced it, because writing the first
two commands showed the rule was buying a storage table and a header to solve problems the
commands had already solved by their own shape.

**A command picks exactly one of these, by its semantics. They are alternatives, not three
things every endpoint implements.**

**1. Stable resource identity.** A create may rely on a client-generated resource UUID when
that UUID completely identifies the intended resource. The retry carries the same id, the
server recognises it, and no second row appears.

> `POST /api/v1/routes` takes a client-generated UUIDv7 as the route's `id`. See
> *Route publication identity* in `docs/architecture.md` for what "the same resource" means
> there, and what a mismatch answers.

**2. Naturally idempotent target-state transition.** A transition with a single target state
may rely on that state, when repeating the command cannot produce a second mutation. There is
nothing to replay because there is nothing that happened twice.

> `POST /api/v1/routes/{routeId}/cancel` is the canonical example: no request body, no
> `expected_status`, no key. Cancelling an already-cancelled route is the same cancellation
> observed again.
>
> **Every command since has chosen this tier.** Phase 13 added withdraw, accept and decline;
> Phase 14 added `trip/start`, `trip/complete` and `trip/abort`. All six are bodyless for the
> same reason. Start is the interesting one: it *creates* a row, so it looks like tier 1 —
> but the client mints no id for it, because a route has at most one trip and
> `unique (route_id)` says so. The route is both the address and the key, which is why Start
> answers `201` the first time and `200` for a repeat while returning the same trip.

**3. Explicit `Idempotency-Key`.** For a command with several meaningful transitions or
outcomes, where neither the resource id nor a single target state is enough to say what the
caller meant. The header is unique per caller and endpoint, with the response replayed.

> Seat-request accept and decline were the expected first case. They shipped in Phase 13 and
> did **not** need it: each names a single target state, so tier 2 was enough. Phase 14's
> three trip commands are the same. **No endpoint uses tier 3**, so none is documented here
> and `idempotency_records` is not created. Tier 3 is built by the command that first needs
> it — twice now, the command that was going to need it did not.

Choosing tier 1 or 2 is not a shortcut past tier 3. It is the observation that a command
whose intent is fully named by a resource id, or whose repetition is a no-op, does not need a
second mechanism to say the same thing — and a mechanism that exists without a consumer is
one more thing to keep correct.

## Concurrency — expected state, not locks

Transition endpoints will take the state the caller believes the resource is in:

```json
{ "expected_status": "pending" }
```

and answer `409` with the actual state when it differs. A driver accepting while a
passenger withdraws is resolved without holding a lock across a mobile round trip, and the
losing client re-renders the new truth rather than showing an error — that is a UI state,
not a failure.

**Still not implemented, and deliberately not.** Seven transitions exist now — route
cancellation, the three seat-request answers, and the three trip lifecycle commands — and
every one of them names a single target state, so re-running it changes nothing and there is
no losing client to re-render. `expected_status` earns its place at the first transition with
several valid source states; until then it would be a field every caller sends and no server
branch reads.

Where two commands genuinely race, the answer has been a row lock rather than a header. A
seat request serializes on `request → route`, and all three trip commands serialize on the
route row alone — see *Trips* in `docs/architecture.md`. That is a lock held for the length
of one transaction, not across a mobile round trip, which is the thing this section refuses.

## Cost-sharing vocabulary

`fare`, `price`, `pricing`, `earnings`, `income`, `payout`, `revenue`, `charge`,
`commission` and `invoice` are forbidden as contract identifiers.

**No cost value appears in the API at all.** No endpoint emits one, no endpoint accepts one,
and the `Route` schema has no such property — Phase 10 decided against a column, a wire
field, a schema property, a calculation helper and a server-side default, because nothing in
the product produces a figure any of them could honestly carry. `cost_share_per_person` is
the term reserved for that concept **if it ever becomes real**; today it names nothing.

Enforced by `tests/Contract/VocabularyTest.php` against the spec, on identifier positions
only so prose may still explain the rule. See `docs/architecture.md` for why this is a
product boundary rather than a style preference.
