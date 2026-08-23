# 5. Opaque access and refresh credentials, not Sanctum

**Status:** accepted (Phase 9). Supersedes the authentication sketch previously recorded in
`architecture.md`, which named Sanctum.

## Context

RideMate needed short-lived access credentials, rotating refresh credentials, reuse
detection, immediate revocation across a device family, and multi-device support — on a
first-party mobile client with no third-party consumers and no browser session.

The Phase 8 sketch proposed **Sanctum for access tokens plus an explicit sessions table** for
rotation. Designing the implementation made the cost of that combination concrete.

## Decision

Mint opaque credentials directly, in two tables of RideMate's own.

## Why not Sanctum

Sanctum contributes bearer parsing, a token table, SHA-256 hashing and a guard. It
contributes **nothing** to refresh rotation, token families or reuse detection — which is the
entire security-relevant part of the design.

Building on it meant two token systems, two hashing paths and two revocation paths, in
exchange for roughly eighty lines of guard code that had to be written either way. The
`personal_access_tokens` table would have sat alongside the sessions table doing a job the
sessions table already did, and the abilities model would have gone unused.

The risk of the alternative is real and stated plainly: a hand-written guard is
security-sensitive code this project now owns. It is mitigated by 256-bit secrets, hash-only
storage, constant-time comparison, domain-separated hashing, and a test suite that covers
rotation, deep reuse, concurrency, revocation and expiry rather than only the happy path.

## Why not JWT

Its one real advantage is verifying without a database read. RideMate requires immediate
revocation — suspension, sign-out, reuse detection — which forces a read on every request. At
that point the complexity is paid for and the benefit is gone. A blocklist would reintroduce
exactly the lookup JWT exists to avoid.

## Why not Passport

OAuth2 ceremony for a first-party mobile client with no third-party consumers buys nothing.

## Consequences

* `auth_sessions` is the revocation family; one `UPDATE` invalidates every credential in it.
* `auth_tokens` is append-only, one row per generation, which is what makes reuse detectable
  for any retained generation rather than only the most recent.
* Reuse detection is bounded by retention, so `token_retention_tail` is a security parameter.
* Rotation is strict: no grace window, and a concurrent duplicate refresh revokes the family.
  Clients must serialise refresh, which the Flutter client does with a single-flight future.
* Swapping the scheme later is confined to `app/Auth`.
