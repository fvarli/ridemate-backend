<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Routes;

use App\Auth\AuthContext;
use App\Http\Requests\DiscoverRoutesRequest;
use App\Http\Responses\DiscoveryPayload;
use App\Routes\DiscoverRoutes;
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
    ): JsonResponse {
        $page = $discover(
            AuthContext::of($request)->account,
            $request->originPlaceId(),
            $request->destinationPlaceId(),
            $request->cursor(),
            $request->limit(),
        );

        return new JsonResponse(DiscoveryPayload::page($page));
    }
}
