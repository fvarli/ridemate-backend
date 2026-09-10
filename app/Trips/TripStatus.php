<?php

declare(strict_types=1);

namespace App\Trips;

/**
 * Where a trip that exists stands.
 *
 * Three cases, because a row exists only once a journey has started. The fourth
 * lifecycle state a caller sees — `not_started` — is deliberately not here: it
 * is what the absence of a row means, and giving it a case would invite
 * somebody to store it. See `App\Trips\TripLifecycle`.
 *
 * WHAT IS ABSENT
 *
 * No `scheduled`, for the reason above. No `expired`: a journey nobody started
 * is a comparison against the clock, not a stored state, and persisting it
 * would need a sweep to keep it true — the argument `App\Routes\RouteStatus`
 * makes for routes. No `cancelled`: cancelling belongs to the route, and a trip
 * that was abandoned after starting is `aborted`, which is a different fact.
 */
enum TripStatus: string
{
    /**
     * A driver pressed Start and the server accepted it.
     *
     * It means that and nothing more. It does not mean the driver is at the
     * origin, is moving, has anybody aboard, or is anywhere in particular —
     * Phase 14 has no location, no geofence and no routing to know any of it.
     */
    case InProgress = 'in_progress';

    /** The driver said the journey was made. */
    case Completed = 'completed';

    /** The driver said it was not, after starting. */
    case Aborted = 'aborted';

    /** Whether this trip can still change. */
    public function isTerminal(): bool
    {
        return $this !== self::InProgress;
    }
}
