<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\SeatRequest;

/**
 * The caller's own asking about each of a set of journeys.
 *
 * ONE QUERY, FOR THE PAGE THAT IS ACTUALLY RETURNED
 *
 * Discovery scans forward through candidates to fill a page, rejecting some as
 * it goes. Resolving a request per candidate would multiply that scan by a
 * query each; resolving one per returned route would be an N+1 on the page.
 * So this runs once, after the page is settled, keyed on the ids that survived.
 *
 * Lifetime uniqueness on `(route_id, account_id)` is what makes the result a
 * map rather than a list: there is at most one asking per member per journey,
 * so a route id cannot collide with itself.
 */
final class MySeatRequestLookup
{
    /**
     * @param  list<string>  $routeIds
     * @return array<string, SeatRequest> keyed by route id
     */
    public function forRoutes(Account $caller, array $routeIds): array
    {
        if ($routeIds === []) {
            return [];
        }

        $found = [];

        $requests = SeatRequest::query()
            ->where('account_id', $caller->id)
            ->whereIn('route_id', $routeIds)
            ->get();

        foreach ($requests as $request) {
            $found[$request->route_id] = $request;
        }

        return $found;
    }
}
