<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Requests\ListRouteSeatRequestsRequest;
use App\Http\Responses\RouteSeatRequestPayload;
use App\SeatRequests\ListRouteSeatRequests;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/routes/{routeId}/seat-requests`
 *
 * Who has asked for a seat on a journey the caller published. The route is
 * fetched scoped to the caller, so a driver asking about somebody else's
 * journey gets the same answer as one asking about a journey that does not
 * exist.
 */
final class ListRouteSeatRequestsController
{
    public function __invoke(
        ListRouteSeatRequestsRequest $request,
        string $routeId,
        ListRouteSeatRequests $list,
    ): JsonResponse {
        $page = $list(
            AuthContext::of($request)->account,
            $routeId,
            $request->cursor(),
            $request->limit(),
        );

        return new JsonResponse(RouteSeatRequestPayload::page($page));
    }
}
