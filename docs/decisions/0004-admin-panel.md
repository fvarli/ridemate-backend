# 4. A custom internal admin panel, not Filament

**Status:** accepted (Phase 8), unimplemented and out of scope until the ops phase.

## Context

The invite-only beta is human-in-the-loop by design: identity review, moderation, report
triage, safety escalation and account intervention are all manual at first. Those need an
authenticated, audited internal surface, and "manual operations" must never mean editing
production rows or SSH-ing into a server.

Filament was originally assumed, and was one of three arguments for choosing Laravel.

## Decision

**The admin surface will be a custom internal panel consuming the backend API.**

## Consequences

* The stack decision loses one of its three original arguments. ADR 0001 records the
  withdrawal rather than quietly keeping it.
* The panel is an API consumer, so it is held to the same OpenAPI contract as the mobile
  client. An internal tool cannot quietly depend on undocumented behaviour, and every
  operator action goes through the same authorization and audit path as everything else.
* Operations endpoints will need their own authorization boundary and audit trail, designed
  with the panel rather than retrofitted.
* Nothing is built now. **No admin or frontend dependency is added in Phase 8**, and none
  should be added before the phase that needs one.
