<?php

declare(strict_types=1);

namespace App\SeatRequests;

/**
 * Why an asking was refused, as a stable machine string.
 *
 * These values are the ones the API will publish as `details.reason` so a
 * client can pick its own approved copy — the server never sends display text.
 * The serialization is B5's; naming them here keeps the domain able to say what
 * it refused without a controller having to guess from an exception message.
 *
 * Deliberately NOT here: a reason for a cancelled or departed route. Discovery
 * withholds those from every non-owner, and `docs/api-conventions.md` says a
 * resource the caller may not see answers 404 rather than a distinguishable
 * refusal — telling a stranger which of two unreachable ids exists is the leak
 * that rule exists to prevent. Those outcomes raise ModelNotFoundException, so
 * they cannot acquire a reason string by accident.
 */
enum RefusalReason: string
{
    /** The caller has no Profile, so the driver would be shown nobody. */
    case ProfileRequired = 'profile_required';

    /** Asking for a seat in your own car. */
    case OwnRoute = 'own_route';

    /**
     * Phase 13 v1 accepts one-off journeys only.
     *
     * A weekday plan has no single departure to hold a seat on, and inventing
     * capacity for an indefinite plan is what `route_occurrences` was deferred
     * to avoid.
     */
    case RecurringRouteUnsupported = 'recurring_route_unsupported';

    /**
     * The supplied request id already identifies somebody else's asking, or the
     * same member's asking about a different journey.
     *
     * Says the id is taken and nothing more.
     */
    case IdAlreadyUsed = 'id_already_used';

    /** This member has already asked about this journey, under another id. */
    case AlreadyRequested = 'already_requested';

    /**
     * The request has been accepted, and acceptance is an end.
     *
     * Nothing in Phase 13 v1 un-accepts: a passenger cannot withdraw a seat the
     * driver agreed to give them, because the driver has already planned around
     * it and nothing tells them it went away.
     */
    case AlreadyAccepted = 'already_accepted';

    /** The request has been declined, and declining is an end too. */
    case AlreadyDecided = 'already_decided';

    /** The passenger withdrew the asking, so there is nothing left to answer. */
    case Withdrawn = 'withdrawn';

    /**
     * The journey was cancelled or has departed, so no NEW seat can be given on
     * it.
     *
     * Only accept raises this. Declining and withdrawing close a request rather
     * than creating an obligation, so a dead journey does not stop either — and
     * the driver owns this route, so naming the reason discloses nothing.
     */
    case RouteUnavailable = 'route_unavailable';

    /** Every offered seat is already accepted. */
    case RouteFull = 'route_full';
}
