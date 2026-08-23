# 3. Spec-first OpenAPI, enforced by tests

**Status:** accepted (Phase 8), implemented.

## Context

The client and the backend live in separate repositories with independent release
cadences. The contract between them therefore has to be an artefact in its own right,
not a by-product.

Laravel has no first-party OpenAPI support, which was the main cost of the stack decision.

## Decision

`openapi/openapi.yaml` is **hand-authored and is the source of truth**. It is never
generated from controllers, and controllers carry no annotations that would regenerate it.

Generation was rejected because it inverts the relationship: the code would lead and the
contract would follow, so an implementation detail could change what RideMate promises
without anyone reviewing a diff.

Synchronisation is a **test**, not a promise. Contract tests run real responses through the
schemas the spec documents, so drift is a failing build.

The invariant:

```
contract change → explicit openapi.yaml diff in review → backend tests pass → client follows
```

## Tooling

PHP-native, and **Node was not required**. `osteel/openapi-httpfoundation-testing` resolves
`devizzent/cebe-php-openapi`, which reads OpenAPI 3.1; that was verified against the actual
document before anything was built on it.

A second, narrower mechanism validates a body against a named component schema using
`opis/json-schema`, because an unknown path under `/api/v1` belongs to no documented
operation but its error body must still match the shared `Error` schema. Documenting a
catch-all operation to avoid that would be inventing a contract for "everything else".

## Consequences

* The spec documents only what the service actually serves. No future endpoints: an
  unimplemented path in a contract is the same class of untruth as a button claiming
  something happened.
* Writing the spec is manual work, deliberately.
* Generated Flutter clients are deferred until a real integration proves they earn their
  complexity.
* A test asserts the spec's error-code enum and the `ApiError` constants are the same set,
  so a code the client cannot translate cannot ship.
