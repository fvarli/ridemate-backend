<?php

declare(strict_types=1);

namespace App\Reviews;

/**
 * Why a review was refused, as a stable machine string.
 *
 * Published as `details.reason` so a client can pick its own approved copy —
 * the server never sends display text. All five arrive with `409 conflict`; the
 * status distinguishes none of them, which is exactly why the reason exists.
 *
 * The command that raises them is B2's. They are named here because the
 * vocabulary is contract, and a reason invented later by whoever writes the
 * command is how a client ends up with a string it cannot translate.
 *
 * DELIBERATELY NOT SHARED WITH THE OTHER DOMAINS
 *
 * `id_already_used` is spelled exactly as `App\SeatRequests\RefusalReason`
 * spells it, because it means the same thing on the same kind of create path —
 * the same wire string for the same meaning is what keeps a client's mapping
 * simple. Sharing one PHP enum across three domains would be a different thing
 * and a worse one: every later addition to any of them would have to be argued
 * in all three.
 *
 * WHAT IS ABSENT, BECAUSE IT CANNOT HAPPEN
 *
 * No `self_review`: a member cannot hold an accepted request on their own route,
 * because asking for a seat in your own car is refused `own_route`. No
 * `not_a_participant`: a caller who is party to neither side gets the
 * non-disclosing 404 every other route-scoped command answers, so nobody can
 * learn which request ids exist by trying to review them. No
 * `profile_required`: a reviewer necessarily has a profile, having asked for or
 * accepted a seat. Phase 14 established that a reason nothing can produce is
 * worse than no reason at all.
 */
enum RefusalReason: string
{
    /**
     * The relationship was never agreed.
     *
     * Reachable and not reducible to either neighbour. A caller is a legitimate
     * party to their own `pending`, `declined` or `withdrawn` request — the
     * passenger holds it, the driver owns the route it sits on — so a 404 would
     * be wrong. And it is independent of the journey: a request can be declined
     * on a route whose trip completed, where `trip_not_completed` would be a
     * false statement about the world.
     */
    case SeatRequestNotAccepted = 'seat_request_not_accepted';

    /**
     * There is no completed journey to rate.
     *
     * Covers a trip that is `in_progress`, one that is `aborted`, and a route
     * with no trip at all. `aborted` means only that a driver moved an
     * already-started trip to that state — not that the journey did not happen,
     * that nobody travelled, or that anybody was absent. It is ineligible as a
     * product rule, not because the service has evidence.
     */
    case TripNotCompleted = 'trip_not_completed';

    /** Fourteen days have passed since the journey was reported as made. */
    case ReviewWindowClosed = 'review_window_closed';

    /** This member has already reviewed this relationship, under another id. */
    case AlreadyReviewed = 'already_reviewed';

    /** The id belongs to a review that is not this one. */
    case IdAlreadyUsed = 'id_already_used';
}
