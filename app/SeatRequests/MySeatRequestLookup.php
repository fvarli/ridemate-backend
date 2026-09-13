<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\Route;
use App\Models\SeatRequest;
use Carbon\CarbonImmutable;

/**
 * The caller's own asking about each of a set of journeys.
 *
 * ONE QUERY, FOR THE PAGE THAT IS ACTUALLY RETURNED
 *
 * Resolved once for the whole page rather than per result: a lookup inside the
 * projection would be an N+1 on the page, and a lookup inside discovery's fill
 * scan would be one per candidate examined — worse, because the scan steps over
 * rows that never reach the caller at all. So this runs after the page is
 * settled, over the ids that survived, and the number of queries does not move
 * when the page grows.
 *
 * A LIST PER ROUTE, NOT ONE ROW
 *
 * Until Phase 16b a route was a journey and `unique (route_id, account_id)`
 * meant a member held at most one asking on it, so a single row per route was
 * the whole truth. A journey is now `(route_id, service_date)` and the
 * uniqueness is dated, so one member may hold Monday's asking and Tuesday's on
 * the same plan. The answer is therefore a list per route, ordered by service
 * date, and a caller that took the first element would be picking a day.
 *
 * ONLY THE DAYS THAT ARE STILL OFFERABLE
 *
 * A recurring plan accumulates askings behind it: last Monday's row is still in
 * the table and always will be, because nothing here deletes history. Returning
 * it would put a departed journey on a discovery card that offers today's, and
 * the member would be reading a state they can no longer act on as though it
 * described the journey in front of them.
 *
 * Which days those are is not decided here. `RequestableJourney` owns it, and
 * owns it for the create path too, so the days discovery shows are exactly the
 * days a create would still accept.
 */
final class MySeatRequestLookup
{
    /**
     * @param  list<Route>  $routes  the routes a page actually returned
     * @return array<string, list<SeatRequest>> keyed by route id, each list in
     *                                          service date order, earliest first
     */
    public function forRoutes(Account $caller, array $routes, ?CarbonImmutable $now = null): array
    {
        if ($routes === []) {
            return [];
        }

        $byId = [];
        foreach ($routes as $route) {
            $byId[$route->id] = $route;
        }

        $found = [];

        // Ordered in SQL rather than after the fact: the order is part of what
        // this returns, and sorting in PHP would let a caller that skipped this
        // method produce a different one. Ties are unrepresentable — dated
        // uniqueness means a member holds at most one asking per journey.
        $requests = SeatRequest::query()
            ->where('account_id', $caller->id)
            ->whereIn('route_id', array_keys($byId))
            ->orderBy('service_date')
            ->get();

        foreach ($requests as $request) {
            $route = $byId[$request->route_id];

            // Every status is kept — declined and withdrawn included. A card
            // must show that this member already asked and was told no, rather
            // than offering to ask again as if nothing had happened.
            if (! RequestableJourney::admits($route, $request->service_date, $now)) {
                continue;
            }

            $found[$request->route_id][] = $request;
        }

        return $found;
    }
}
