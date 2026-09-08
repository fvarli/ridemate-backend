# 7. How often a member may change their display name

**Status:** accepted (Phase 12), **not implemented**. A profile-hardening requirement for beta.
Phase 11 ships an unrestricted `PUT /api/v1/me/profile`, and nothing in this document changes
that yet.

## Context

Phase 11 gave an account a public identity: one display name, and the initials the server draws
from it. A member sets it once at setup and may change it whenever they like, as many times as
they like.

That is the right place to have stopped. A rate limit with nothing to protect is a rule looking
for a reason, and Phase 11 had no readers of a display name other than its owner.

Phase 12 changes the audience. A discovery result names the member who published a route, so a
display name stops being something you see about yourself and becomes something a stranger
uses to decide whether to travel with you. Two problems arrive with that audience, and neither
existed before:

**Recognition.** Somebody who agreed to share a journey with a name they remember should still
find that name afterwards. A member who renames freely between publication and departure can
present a different identity at each step of the same arrangement, and the other person has no
way to tell whether they are dealing with the same human.

**Evasion.** Once reputation exists — Phase 15 onwards — a name that can be changed hourly is
the cheapest possible way to shed a history. The reputation itself will be bound to the
account rather than the name, but the thing a member actually recognises is the name.

Neither problem is urgent in Phase 12, because there is no reputation yet and no seat request
to be recognised across. Both become urgent before beta, and the policy is cheaper to agree now
— while the surface is one field and one endpoint — than after something depends on it.

## Decision

**Creating a profile is unrestricted.** Setup asks once, and a member who has never had a name
is not rate-limited into an empty state.

**A correction window of twenty-four hours from creation, worth one change.** Typos, a name
entered in the wrong field, a second thought about what strangers should see: these happen
immediately or not at all, and treating the first day's fix as a "change" would spend a
thirty-day allowance on a mistake nobody meant to make.

**After that, one real change every thirty days.** Long enough that a name is worth recognising
and short enough that a member who marries, transitions, or simply chose badly is not stuck
with it for a year.

**Saving the same normalized target state does not consume a change.** The endpoint is a
target-state write and a retry over a dropped connection must stay safe — that property is the
reason it carries no `Idempotency-Key`, and a cooldown that counted retries would quietly
destroy it. What counts is a change in the stored name after normalization: same trimmed name
in, no allowance spent, whatever the client did.

**Enforcement is the server's.** The client may render a cooldown it was told about; it may not
be the thing that decides. A rule enforced in the app is a rule that stops existing the moment
somebody calls the API directly, which is precisely the case a rename limit is for.

**Previous names are never exposed.** Not in the profile representation, not in a discovery
result, not in any member-facing response. An internal audit trail may be retained for abuse
handling, and if it is, it is operator-facing only. Publishing a rename history would turn a
protection against evasion into a permanent public record of a member's old names — including
names somebody may have changed for their safety.

**A verified or legal identity is a different concept and gets its own field.** `display_name`
is what a member calls themselves. Verification, when it arrives, establishes who they are.
Collapsing the two would mean either a verified member cannot choose what to be called, or a
chosen name inherits an authority nobody granted it.

## Consequences

* **Phase 12 implements none of this.** No column, no cooldown check, no `429`, no
  `retry_after`, no client countdown, no test asserting a limit. A rule half-built is a rule
  that fails open, and Phase 12's own scope is discovery.
* The shape of the future work is known and small: a timestamp of the last effective change on
  `profiles`, a refusal in `SaveProfile`, and a documented status on the endpoint. Nothing in
  Phase 11's schema or contract has to move to accommodate it.
* **The refusal status is deliberately left open.** `409` and `429` both have arguments, and
  choosing one before the endpoint exists would pick a wire contract without a caller to test
  it against.
* This is a **beta blocker**, alongside the production SMS adapter — not a phase. It ships
  before strangers can see each other's names in a released build, whichever phase that turns
  out to be.
* Normalization is already the server's: `App\Profiles\DisplayName` trims and validates, and
  the same value object decides what "the same name" means when the cooldown is built. It does
  not gain a second definition.
