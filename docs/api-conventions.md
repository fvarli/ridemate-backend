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
client owns 210 approved localization keys with Turkish as the source language; if the
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

When pagination arrives it is cursor-based: `?cursor=…&limit=…`, with the next cursor in
the response.

Offset pagination over a feed that mutates — journeys filling up, requests being accepted —
shows duplicates and silently skips items as rows shift between pages. That is a
correctness problem, not a performance one, and it is invisible in testing against static
data.

## Idempotency — defined now, implemented with the first command

Every non-GET that creates or transitions an entity will require an `Idempotency-Key`
header, unique per caller and endpoint, with the response replayed for 24 hours.

Mobile retries are not hypothetical. "The passenger sent two requests for the last seat
because the tunnel dropped" is a support incident on day one, and the fix has to exist
before the endpoint does.

**Not implemented in Phase 8**, which has no command endpoint. No storage table exists yet.

## Concurrency — expected state, not locks

Transition endpoints will take the state the caller believes the resource is in:

```json
{ "expected_status": "pending" }
```

and answer `409` with the actual state when it differs. A driver accepting while a
passenger withdraws is resolved without holding a lock across a mobile round trip, and the
losing client re-renders the new truth rather than showing an error — that is a UI state,
not a failure.

**Not implemented in Phase 8**, which has no lifecycle.

## Cost-sharing vocabulary

`fare`, `price`, `pricing`, `earnings`, `income`, `payout`, `revenue`, `charge`,
`commission` and `invoice` are forbidden as contract identifiers. The approved term is
`cost_share_per_person`: read-only on every response, accepted as input by no endpoint.

Enforced by `tests/Contract/VocabularyTest.php` against the spec, on identifier positions
only so prose may still explain the rule. See `docs/architecture.md` for why this is a
product boundary rather than a style preference.
