<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Routes;

use App\Auth\AuthContext;
use App\Http\Requests\DiscoverRoutesRequest;
use App\Http\Responses\DiscoveryPayload;
use App\Routes\DiscoveredRoute;
use App\Routes\DiscoverRoutes;
use App\SeatRequests\MySeatRequestLookup;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/routes/discover`.
 *
 * Four lines of work, and that is the point: which routes are eligible, how a
 * page is filled and what the cursor means are all decided in App\Routes, so
 * they read the same way whether they are reached from HTTP or from anywhere
 * else. This resolves the caller, hands over the request's own values, and
 * projects the answer.
 *
 * The searcher comes from the credential. There is no parameter naming a member
 * — one would let a caller ask what somebody else would see, and the query
 * excludes the searcher's own routes, so it would also let them probe whose
 * routes are whose.
 */
final class DiscoverRoutesController
{
    public function __invoke(
        DiscoverRoutesRequest $request,
        DiscoverRoutes $discover,
        MySeatRequestLookup $mine,
    ): JsonResponse {
        $caller = AuthContext::of($request)->account;

        $page = $discover(
            $caller,
            $request->originPlaceId(),
            $request->destinationPlaceId(),
            $request->cursor(),
            $request->limit(),
        );

        // After the page is settled, never during the fill scan: one query for
        // the ids that actually survived, rather than one per candidate looked
        // at or one per route returned.
        $routeIds = array_map(
            static fn (DiscoveredRoute $found): string => $found->route->id,
            $page->routes,
        );

        return new JsonResponse(
            DiscoveryPayload::page($page, $mine->forRoutes($caller, $routeIds)),
        );
    }
}
