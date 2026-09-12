<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Account;
use App\Models\Route;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The route a driver may command, locked, and the trip it has.
 *
 * AUTHORIZATION IS IN THE QUERY, AND THE LOCK IS ON THE SCOPED ROW
 *
 * Every trip command needs the same two things in the same order, and an
 * authorization rule copied into three places is an authorization rule that
 * will eventually differ in one of them. Fetching by id and checking ownership
 * afterwards would also mean an unauthorized caller could hold a lock on
 * somebody else's route for the length of a transaction, on a row whose id
 * they only had to guess.
 *
 * A miss is a `ModelNotFoundException` whatever the cause — no such route, or
 * one belonging to somebody else. `docs/api-conventions.md`: a resource the
 * caller may not see answers 404, because distinguishing it from 403 tells
 * them the resource exists.
 *
 * LOCK ORDER
 *
 * The route, and only the route. Nothing in this domain locks a seat request,
 * so Phase 13's `request → route` remains the single two-resource order in the
 * system and a cycle stays unreachable.
 */
final class OwnedRoute
{
    public function lock(Account $driver, string $routeId): Route
    {
        $route = Route::query()
            ->where('account_id', $driver->id)
            ->lockForUpdate()
            ->find($routeId);

        if (! $route instanceof Route) {
            throw (new ModelNotFoundException)->setModel(Route::class, [$routeId]);
        }

        return $route;
    }

    /**
     * The trip of one dated journey, read inside the lock the caller holds.
     *
     * The date is required rather than derived: a route is a plan, and asking
     * it for "its" trip without saying which journey is the question Phase 16a
     * exists to stop anything asking. `first()` is exact here rather than a
     * choice — `unique (route_id, service_date)` means the predicate below
     * matches at most one row.
     *
     * Null is the ordinary answer rather than missing data: most journeys have
     * never been started, which is `not_started`.
     */
    public function tripOn(Route $route, ?CarbonImmutable $serviceDate): ?Trip
    {
        // A recurring plan has no single journey, so there is nothing to look
        // for. Null rather than an exception because the callers must still
        // reach their own refusals: Start answers `recurring_route_unsupported`
        // a few lines later, and Complete and Abort answer `trip_not_started`,
        // which is exactly what they answered before a date existed.
        if ($serviceDate === null) {
            return null;
        }

        return $route->trips()
            ->where('service_date', $serviceDate->toDateString())
            ->first();
    }
}
