# 1. Laravel as the backend stack

**Status:** accepted (Phase 8), with one argument since withdrawn — see below.

## Context

RideMate needed a backend for a small invite-only İstanbul beta with human-in-the-loop
operations, no in-app money, PostgreSQL with PostGIS, and a Dart mobile client. The owner
is strong in both PHP/Laravel and Node/NestJS, so generic arguments cancelled out.

## Decision

**Laravel**, on PHP 8.4 with PostgreSQL 16 and PostGIS 3.

Three arguments carried it, all present-tense rather than speculative:

1. The beta is explicitly human-in-the-loop, and an internal ops surface would be far
   faster to reach with Filament than with anything in the Node ecosystem.
2. Redis is excluded by decision; Laravel's database queue driver honours that natively,
   whereas the practical NestJS queue answer needs Redis on day one.
3. The usual TypeScript argument does not apply — the client is Dart, so there is no shared
   type system to gain.

The honest cost is OpenAPI: NestJS generates a spec from decorators and Laravel does not.
That is mitigated deliberately in ADR 0003 rather than waved away.

## Withdrawn argument

**Argument 1 no longer applies.** The product owner has since decided that RideMate's admin
surface will be a **custom internal panel consuming the backend API, not Filament**.

The decision to use Laravel stands on arguments 2 and 3, plus first-class migrations and
testing and Reverb available when realtime arrives. But the withdrawal is recorded here
rather than left in place, because a justification that no longer holds quietly makes the
whole decision look better-supported than it is.

## Consequences

* Postgres-backed queues when the first job arrives; Redis only when something measures a
  need for it.
* Reverb is available for realtime in Phase 11 without changing stack.
* The OpenAPI discipline must be enforced by tests rather than by generation.
* The future admin panel is an API consumer, which means it is held to the same contract as
  the mobile client — it cannot depend on undocumented behaviour.
