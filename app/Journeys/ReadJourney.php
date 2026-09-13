<?php

declare(strict_types=1);

namespace App\Journeys;

use App\Models\Account;
use App\Models\Route;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * One dated journey of the caller's own route, addressed directly.
 *
 * WHY THIS EXISTS BESIDE THE FEED
 *
 * The feed is bounded on purpose — today, plus anything still under way — so a
 * driver reading last Tuesday, or next Tuesday, has nothing to page through.
 * This is the addressed read: the caller names the route and the day, and gets
 * the truth about that journey whether it is ahead, behind, started or not.
 * A client that had to scan a feed to answer "what happened on the 9th" would
 * be doing the server's work and would sometimes be unable to.
 *
 * EVERY REFUSAL IS THE SAME 404
 *
 * No such route, somebody else's route, a malformed day, or a day this route
 * does not run on — all answer identically. `docs/api-conventions.md`: a
 * resource the caller may not see answers 404, because distinguishing the
 * reasons tells them which routes exist and which days somebody else's plan
 * runs on. Ownership is in the query rather than checked afterwards, so a
 * stranger's route is never loaded at all.
 *
 * IT DOES NOT SAY A JOURNEY HAPPENED
 *
 * A day the route runs on is a day this route has a journey for, in the sense
 * that it can be addressed. Whether anybody made it is `trip`, and for almost
 * every day the honest answer is `not_started`.
 */
final class ReadJourney
{
    public function __invoke(Account $driver, string $routeId, string $serviceDate): Journey
    {
        $day = ServiceDate::parse($serviceDate);

        if (! $day instanceof CarbonImmutable) {
            $this->noSuchJourney($routeId);
        }

        $route = Route::query()
            ->with(['originPlace', 'destinationPlace'])
            ->where('account_id', $driver->id)
            ->find($routeId);

        if (! $route instanceof Route) {
            $this->noSuchJourney($routeId);
        }

        // The recurrence decides, in the one place that owns it. A Saturday on
        // a weekday plan, or any day but its own on a one-off route, is not a
        // journey this route has — and answering for it would invent one.
        if (! $route->runsOn($day)) {
            $this->noSuchJourney($routeId);
        }

        return new Journey($route, $day, $this->tripOn($route, $day));
    }

    /**
     * The trip of exactly this journey, or none.
     *
     * Scoped by `(route_id, service_date)`, which `unique (route_id,
     * service_date)` makes an exact match rather than a pick. Nothing here takes
     * a route's first, latest or only trip: a recurring plan has many, and any
     * of those would answer about a different day.
     */
    private function tripOn(Route $route, CarbonImmutable $day): ?Trip
    {
        return $route->trips()
            ->where('service_date', $day->toDateString())
            ->first();
    }

    /**
     * The non-disclosing answer.
     *
     * A `ModelNotFoundException` rather than a refusal reason, so a 404 cannot
     * acquire a distinguishable machine string by somebody later adding one.
     * Throws rather than returns, so every caller is a dead end and PHPStan
     * knows it.
     */
    private function noSuchJourney(string $routeId): never
    {
        throw (new ModelNotFoundException)->setModel(Route::class, [$routeId]);
    }
}
