# RideMate — Backend

The API behind RideMate, a trust-first journey-sharing product for İstanbul.

RideMate is **not** taxi or ride-hailing. A driver is already making the journey; the
product connects people whose routes are compatible and lets them share the journey's
legitimate costs. That framing is enforced in this repository rather than merely
described: a test rejects taxi and payment vocabulary in the API contract.

The Flutter client lives in a separate repository and develops against
[`openapi/openapi.yaml`](openapi/openapi.yaml), not against this code.

## Status

**Phase 8 — bootstrap and API contract foundation.**

This service boots, connects to PostgreSQL, enables PostGIS, reports liveness and
readiness, answers every error in one documented shape, carries a correlation id through
its logs, and is held to a hand-written OpenAPI contract by its own tests.

**It has no product domain.** No accounts, no authentication, no routes, no trips, no
messages — and no tables for any of them. The database contains exactly two tables:
Laravel's own `migrations` ledger and PostGIS's `spatial_ref_sys`. The versioned API at
`/api/v1` exists, is empty, and gains its first endpoints with authentication in Phase 9.

That emptiness is the deliverable. Schema written before the endpoints that use it is
schema written against a guess, and the account model in particular is the thing most
likely to change once the real verification flows are designed.

## Requirements

| Tool | Version |
|---|---|
| PHP | **8.4** |
| Composer | 2.x |
| PostgreSQL | 16 |
| PostGIS | 3.x |

**Local development is native. There is no Dockerfile and no compose file**, and Docker is
not required to run or test this project. CI uses a PostGIS service container, which is
isolated infrastructure on a throwaway runner and changes nothing here.

### One-time system prerequisite

PostGIS is a separate system package and does not arrive with PostgreSQL:

```bash
sudo apt install postgresql-16-postgis-3
```

The extension itself is enabled by a migration, so a fresh database needs no manual SQL.

## Setup

```bash
composer install
cp .env.example .env
php8.4 artisan key:generate
```

Create both databases. The test suite is destructive and must never touch the development
one:

```bash
createdb ridemate
createdb ridemate_test
```

Then set `DB_PASSWORD` in `.env`. **`.env` is git-ignored and must never be committed;**
`.env.example` carries placeholders only and no real credential belongs in it.

```bash
php8.4 artisan migrate
php8.4 artisan serve
```

```bash
curl localhost:8000/health
curl localhost:8000/ready
```

## PHP 8.4

RideMate requires PHP 8.4, and `tool/check.sh` refuses to run on anything else. If the
system default `php` is older — as it is on the primary development machine — invoke the
correct binary explicitly:

```bash
php8.4 artisan …
php8.4 "$(which composer)" …
```

Nothing here changes the system PHP, and no `update-alternatives` is required.

## Quality gates

One reproducible entry point, used locally and by CI:

```bash
./tool/check.sh
```

which runs, and requires a clean result from:

```bash
php8.4 vendor/bin/pint --test          # formatting
php8.4 vendor/bin/phpstan analyse      # static analysis, level 8
php8.4 vendor/bin/phpunit              # unit, feature and contract tests
```

The OpenAPI contract tests and the vocabulary guard live inside the PHPUnit suite rather
than as separate steps, because a contract check that can be skipped is a contract check
that eventually is.

CI runs this same script rather than a copy of its steps, so the two cannot drift apart.

## The API contract

[`openapi/openapi.yaml`](openapi/openapi.yaml) is the **source of truth**. It is
hand-authored and never generated from controllers: generation would invert the
relationship, letting an implementation detail change what RideMate promises without
anyone reviewing a diff.

Synchronisation is enforced rather than trusted — contract tests run real responses
through the schemas the spec documents, so drift is a failing build. The spec describes
only what the service actually serves today.

## Documentation

| Document | Contents |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | Stack, structure, identifiers, auth architecture, module roadmap, non-scope |
| [`docs/api-conventions.md`](docs/api-conventions.md) | Versioning, naming, pagination, idempotency, errors, vocabulary boundary |
| [`docs/decisions/`](docs/decisions/) | Architecture decision records |
