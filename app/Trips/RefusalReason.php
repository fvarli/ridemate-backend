<?php

declare(strict_types=1);

namespace App\Trips;

/**
 * Why a trip lifecycle command was refused, as a stable machine string.
 *
 * Published as `details.reason` so a client can pick its own approved copy —
 * the server never sends display text. The serialization is B5's; naming them
 * here lets the domain say what it refused without a controller guessing from
 * an exception message.
 *
 * DELIBERATELY NOT `App\SeatRequests\RefusalReason`
 *
 * Two of these strings — `route_unavailable` and `recurring_route_unsupported`
 * — are also seat-request reasons, and that is intentional: the same wire
 * string for the same meaning is what makes a client's mapping simple. Sharing
 * one PHP enum across two domains would be a different thing entirely, and a
 * worse one: seat requests would own vocabulary trips depend on, and every
 * later addition to either would have to be argued in both.
 *
 * There is no `trip_already_started`. It would only ever have described a route
 * cancellation refused because a trip exists, and that refusal is unreachable:
 * a one-off route is cancellable only while its departure is upcoming, and a
 * trip can only start once it is past. Repeating Start on a running trip is a
 * success, not a refusal.
 */
enum RefusalReason: string
{
    /**
     * A weekday plan has no single departure to make.
     *
     * Phase 14 supports one-off journeys only, and `route_occurrences` stays
     * unbuilt until something concrete needs a per-day row.
     */
    case RecurringRouteUnsupported = 'recurring_route_unsupported';

    /**
     * The scheduled departure has not arrived yet.
     *
     * No grace window. "Fifteen minutes early" would be a number nobody chose,
     * and the server is the clock.
     */
    case DepartureNotReached = 'departure_not_reached';

    /** The journey was withdrawn, so there is nothing to make. */
    case RouteUnavailable = 'route_unavailable';

    /** There is no trip to complete or abort — nobody started one. */
    case TripNotStarted = 'trip_not_started';

    /** The journey was already reported as made. */
    case AlreadyCompleted = 'already_completed';

    /** The journey was already abandoned. */
    case AlreadyAborted = 'already_aborted';
}
