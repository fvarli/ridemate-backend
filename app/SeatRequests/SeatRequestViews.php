<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Profile;
use App\Models\SeatRequest;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Building a read-model tuple from a row, in one place.
 *
 * The listings need it per page, and the command responses need it for the one
 * row they just changed. Two copies would be two places for the profile
 * invariant below to drift apart.
 *
 * A MISSING PROFILE FAILS
 *
 * Asking requires a profile, and a route can only be discovered — and so only
 * requested — when its owner has one. Neither can be absent. If one is, this
 * raises rather than synthesising a name or blank initials: a fabricated
 * identity on the screen that names the stranger somebody is deciding whether
 * to travel with is the worst available outcome.
 */
final class SeatRequestViews
{
    public function own(SeatRequest $request, ?CarbonImmutable $now = null): OwnSeatRequest
    {
        // No guard on the route: the foreign key is NOT NULL and cascades, so
        // a request without its journey cannot exist, and a dead check would
        // only read as if it could.
        $route = $request->route;
        $driver = $route->account->profile;

        if (! $driver instanceof Profile) {
            throw new RuntimeException("Route {$route->id} has no driver profile.");
        }

        return new OwnSeatRequest($request, $route, $route->departureState($now), $driver);
    }

    public function incoming(SeatRequest $request): IncomingSeatRequest
    {
        $passenger = $request->passenger->profile;

        if (! $passenger instanceof Profile) {
            throw new RuntimeException("Seat request {$request->id} has no passenger profile.");
        }

        return new IncomingSeatRequest($request, $passenger);
    }
}
