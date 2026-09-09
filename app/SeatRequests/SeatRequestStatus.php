<?php

declare(strict_types=1);

namespace App\SeatRequests;

/**
 * Where one asking stands.
 *
 * Four states, and only `pending` is non-terminal. `accepted`, `declined` and
 * `withdrawn` are ends: nothing in Phase 13 v1 reopens a decided request, and
 * the lifetime uniqueness of (route, member) means there is no second asking to
 * open either.
 *
 * WHAT IS ABSENT, AND WHY
 *
 * No `expired`. A request on a journey that has departed is not a stored state,
 * it is a comparison against the clock — the same argument `App\Routes\RouteStatus`
 * makes for routes, and it holds here for the same reason: persisting it would
 * need a sweep to keep it true, and the row would be "not expired yet" for as
 * long as that job was late.
 *
 * No `cancelled_by_route`. When a driver withdraws a journey the request does
 * not change — it remains the historical truth of what this member asked and
 * what answer they got. The route's own `status` says the journey is gone, and
 * a client renders the two as independent facts rather than one synthesised
 * state that claims a decision nobody made.
 *
 * No `confirmed`, `booked` or `reserved`. RideMate does not sell a seat; a
 * driver agrees to share one.
 */
enum SeatRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    /**
     * Whether this asking can still change.
     *
     * The single place that knows which states are ends, so a command does not
     * have to spell the set out again and cannot spell it out differently.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
