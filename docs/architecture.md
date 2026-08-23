# RideMate Backend — Architecture

> **Status:** Phase 8 complete — the service boots, connects to PostgreSQL with PostGIS,
> reports liveness and readiness, has one error contract, and is governed by a spec-first
> OpenAPI document enforced by tests. **There is no product domain and no authentication.**

## Product framing

RideMate is journey sharing and cost sharing. A driver is already making the trip; the
product connects compatible people and lets them share the journey's legitimate costs.

It is **not** taxi, ride-hailing, chauffeur or commercial passenger transport. This is a
constraint on the code, not a marketing sentence — see *Cost-sharing vocabulary* below.

## Stack

```
Laravel 13  ·  PHP 8.4  ·  PostgreSQL 16 + PostGIS 3  ·  PHPUnit  ·  GitHub Actions
```

Laravel was chosen over NestJS for this domain. The reasoning is recorded in
`docs/decisions/0001-backend-stack.md`, including one argument that has since been
withdrawn — see *Admin and operations*.

**Not installed, and each for a reason rather than an oversight:** Sanctum (no auth yet),
Redis (Postgres-backed queues cover the first jobs; add it when something measures a need),
Reverb (realtime is Phase 11), a queue driver (no job exists), a spatial ORM package
(corridor matching will be raw PostGIS SQL), any observability SDK, any Docker file.

## Local development

Native. PHP, Composer and PostgreSQL run directly on the machine; there is no Dockerfile
and no compose file, and Docker is not required to run or test this project.

CI uses a PostGIS service container. That is not a reversal: the decision is about local
development and the repository runtime setup, and a throwaway runner container changes
neither.

## Structure

Standard Laravel, deliberately. `app/Modules/` does **not** exist.

The intent is a **modular monolith** — one deployable application with domain modules that
do not import each other, mirroring the client's test-enforced "features never import each
other" rule, which has held for seven phases. But a module directory with no modules in it
is a naming convention pretending to be an architecture, and a cross-module lint rule with
zero modules enforces nothing. Phase 9 creates the first real domain boundary and earns
the structure then.

**No microservices, no message bus, no CQRS framework, no event sourcing, no Kubernetes.**
None has a present-tense driver at one city, one team and one client, and each is
operational surface that has to be maintained rather than a capability that arrives free.

## Identifiers

**UUIDv7, in native PostgreSQL `uuid` columns**, as the single public identifier strategy.

Time-ordered, so it indexes without the fragmentation random UUIDv4 causes; sixteen bytes
rather than a string; first-party in Laravel via `HasVersion7Uuids`; and not enumerable
the way an exposed integer key is. No dual integer-plus-UUID keys — the second key buys
nothing once v7 solves locality. ULID was the runner-up and loses only on native column
type.

**Nothing implements this yet**, because no table exists. It is recorded so Phase 9 applies
a decision rather than making one under time pressure.

A request id is *not* a domain identifier. It is a transport concern that lives for one
request and is stored against nothing; the `X-Request-Id` header keeps the two apart.

## Authentication — decided, not built

None of this exists in Phase 8. No package is installed, no table is created, no endpoint
is exposed. It is written down so Phase 9 implements a decision.

* **Phone (E.164) is the primary identifier.** Türkiye pilot, and the client's verification
  model already declares `phone, email, identity, selfie, licence`. Email is secondary —
  recovery and account-level notices — and never the primary credential.
* **No password.** Phone possession via OTP is the credential, so there is nothing to hash,
  leak or reset. If one is ever added it goes through Laravel's hasher.
* **Short-lived access token plus a rotating refresh token**, with reuse detection, bound
  to a device/session row, individually revocable, and listable to the member so
  "sign out other devices" is possible.
* **Sanctum for access tokens plus an explicit sessions table** for rotation, which Sanctum
  does not provide. Not Passport: OAuth2 ceremony for a first-party mobile client buys
  nothing.
* **Account status** (`pending`, `active`, `suspended`, `deactivated`, `deleted`) is
  distinct from verification state and from onboarding.

**Four concepts stay separate**, as they have across the whole product: onboarding is a
device-local flag on the client; authentication does not exist yet; verification is
identity checking; profile is presentation. Nothing may couple them.

An OTP provider is not selected. It needs an endpoint to serve and a Türkiye
deliverability and cost evaluation — and it should be chosen together with the safety-SMS
requirement, because the safety channel has the harder reliability bar and should lead.

## Admin and operations

The invite-only beta is explicitly human-in-the-loop: identity review, moderation, report
triage, safety escalation and account intervention are all manual at first. That requires
an authenticated, audited internal surface — and manual operations must never mean editing
production rows or SSH-ing into a server.

**The admin surface will be a custom internal panel consuming this API. Filament is not
the plan.** Nothing for it is designed or installed in Phase 8.

This reverses part of the original stack argument, and the reversal is recorded rather than
quietly dropped: Filament's speed was one of three reasons Laravel was chosen over NestJS.
The choice still stands on the other two — Postgres-backed queues without forcing Redis,
and no TypeScript type-sharing benefit against a Dart client — plus first-class migrations
and testing and Reverb available later. But an argument that no longer applies should not
keep sitting in the justification as though it does.

A practical consequence worth noting: an API-consuming admin panel is held to the same
OpenAPI contract as the mobile client, which is a genuine benefit — the internal tool
cannot quietly depend on undocumented behaviour.

## Trust Score and matching — named, not specified

Both are backend-owned. Neither is designed here, and the client is forbidden from
computing either.

A **Trust Score** service must consider identity verification level, account age, completed
journeys, cancellation behaviour, ratings received, reports upheld, fraud signals and
device risk. It must **not** derive from verification step counts — the approved design
shows two verified steps against a score of 60, which disproves any step-derived formula —
nor define colour thresholds, nor assert points per journey. The scoring policy, the appeal
path and whether a member may see their own breakdown require separate product, legal and
safety approval.

A **matching** service must consider geographic corridor overlap, driver detour cost,
temporal overlap, seat availability, mutual blocks and verification preferences. Build the
filter, defer the ranking: the server returns an explicit order and the client renders it
verbatim, which is the contract the client already implements. Two policy-flagged
preferences stay inert until legal, safety and product review completes.

## Cost-sharing vocabulary

`fare`, `price`, `pricing`, `earnings`, `income`, `payout`, `revenue`, `charge`,
`commission` and `invoice` may not appear as identifiers in the API contract — field names,
path segments, parameter names, enum values or operation ids. The approved term for the
future contribution concept is `cost_share_per_person`; it will be read-only on every
response and accepted as input by no endpoint.

`tests/Contract/VocabularyTest.php` enforces this against `openapi/openapi.yaml`. It
inspects identifier positions only, so the contract can explain *why* the words are
forbidden without tripping its own rule — something a grep could not do. The guard has its
own test, which caught a real defect on the day it was written: the tokenizer split
snake_case but not camelCase, so `driverEarnings` would have passed.

This is a product and regulatory-characterisation boundary, not a style preference.
Whether driver-set cost sharing may ever become editable is a question for legal and
product review, not for a schema author.

## Module roadmap

Phase 9 onward, in dependency order. Only the module a phase earns is created.

| Phase | Module |
|---|---|
| 9 | Auth / Accounts · Profiles · Verification |
| 10 | Vehicles · Routes · Route occurrences · Discovery |
| 10 | Seat requests · Participants · Notifications |
| 11 | Trips · Chat · Reviews |
| 12 | Safety · Trusted contacts · Moderation |

## Phase 8 non-scope

No product table of any kind. Specifically **not created**: `users`, `sessions`,
`refresh_tokens`, `password_reset_tokens`, `personal_access_tokens`, `profiles`,
`verifications`, `vehicles`, `routes`, `route_occurrences`, `seat_requests`,
`participants`, `trips`, `trip_locations`, `conversations`, `messages`, `reviews`,
`trusted_contacts`, `safety_incidents`, `blocks`, `reports`, `notifications`, `devices`,
`idempotency_records`, `audit_events`, `jobs`, `failed_jobs`, `cache`.

Laravel's default users, session and cache migrations were **deleted** rather than edited.
They encode an email-and-password account a phone-first product has not approved, and a
wrong table is harder to remove later than to never create. The `User` model, its factory
and `config/auth.php` went with them.

**Rate limiting is deferred to Phase 9.** Phase 8 exposes no route it could throttle, so
wiring a limiter would mean a cache store, a cache table and middleware with no traffic —
exactly the placeholder infrastructure this phase refuses. The policy is decided (per-IP
and per-account limits, database cache store) so Phase 9 implements it rather than invents
it.
