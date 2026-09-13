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
 * One of these strings — `route_unavailable` — is also a seat-request reason,
 * and that is intentional: the same wire string for the same meaning is what
 * makes a client's mapping simple. Sharing one PHP enum across two domains
 * would be a different thing entirely, and a worse one: seat requests would own
 * vocabulary trips depend on, and every later addition to either would have to
 * be argued in both.
 *
 * `recurring_route_unsupported` used to be the second. Phase 16b let a
 * passenger ask for a seat on a named day of a weekday plan, so the seat-request
 * side can no longer produce it and stopped publishing it. It survives here
 * because the older route-only trip endpoints still address one-off journeys
 * only — see `CommandedJourney`.
 *
 * `service_date_passed` is the third, added in Phase 16b, and the same
 * reasoning applies to it: one word, one meaning, two independent enums.
 *
 * There is no `trip_already_started`. It would only ever have described a route
 * cancellation refused because a trip exists, and nothing refuses that:
 * `CancelRoute` never reads a trip. Phase 14 justified this by saying the two
 * windows could not overlap — a one-off route being cancellable only while its
 * departure is upcoming, and a trip only startable once it is past. That was
 * true of one-off routes and is not true of plans: a weekday plan is upcoming
 * for ever, so it can be cancelled while Tuesday's journey is under way. The
 * conclusion survives the correction for a better reason. Cancelling a plan
 * says no FUTURE journey will run; it does not rewrite a journey already
 * happening, and the driver on one must still be able to finish or abandon it.
 * Repeating Start on a running trip is a success, not a refusal.
 */
enum RefusalReason: string
{
    /**
     * A route-only endpoint cannot address a plan's journey.
     *
     * Not "recurring journeys are unsupported" any more — Phase 16b starts,
     * completes and abandons them by date. It means the caller used the form
     * that names no day on a route that has many, and nothing may guess which
     * one they meant.
     */
    case RecurringRouteUnsupported = 'recurring_route_unsupported';

    /**
     * The scheduled departure has not arrived yet.
     *
     * No grace window. "Fifteen minutes early" would be a number nobody chose,
     * and the server is the clock.
     */
    case DepartureNotReached = 'departure_not_reached';

    /**
     * The day this journey runs on is over, where the route is.
     *
     * A plan's journey belongs to one calendar day in the route's own zone.
     * Once that day has ended there, Start would be recording a journey as
     * beginning on a date that is behind the driver — so it is refused, and
     * the next day's journey is a different one with its own command.
     *
     * `App\SeatRequests\RefusalReason` publishes the same string for its own
     * reason, and the two stay separate PHP enums deliberately: the same wire
     * word for the same meaning keeps a client's mapping simple, while one
     * domain owning the other's vocabulary would not.
     */
    case ServiceDatePassed = 'service_date_passed';

    /** The journey was withdrawn, so there is nothing to make. */
    case RouteUnavailable = 'route_unavailable';

    /** There is no trip to complete or abort — nobody started one. */
    case TripNotStarted = 'trip_not_started';

    /** The journey was already reported as made. */
    case AlreadyCompleted = 'already_completed';

    /** The journey was already abandoned. */
    case AlreadyAborted = 'already_aborted';
}
