<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Profile;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Routes\DepartureState;

/**
 * One of the caller's own askings, with the journey context it needs.
 *
 * Two independent truths travel together here and neither overwrites the other:
 * the request's own status is history — what this member asked and what answer
 * they got — while the route's status and departure state are the journey as it
 * stands now. A request stays `accepted` beside a route that is `cancelled`,
 * because both are true and inventing a third state to reconcile them would
 * claim a decision nobody made.
 *
 * `departureState` is computed once, against the clock the read used, so a
 * later projection cannot recompute it against a different one.
 *
 * The driver is a `Profile` and only a `Profile`: a display name and the
 * initials derived from it. There is no Account here, so no phone number or
 * credential can reach a projection by accident.
 */
final readonly class OwnSeatRequest
{
    public function __construct(
        public SeatRequest $request,
        public Route $route,
        public DepartureState $departureState,
        public Profile $driver,
    ) {}
}
