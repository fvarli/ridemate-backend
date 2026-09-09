<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Requests\ListMySeatRequestsRequest;
use App\Http\Responses\MySeatRequestPayload;
use App\SeatRequests\ListMySeatRequests;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/me/seat-requests`
 *
 * A member's own history, newest first, including askings on journeys that
 * were later cancelled or have departed. This is the only surface that says
 * what came of a request, so nothing is filtered by what discovery would show.
 */
final class ListMySeatRequestsController
{
    public function __invoke(
        ListMySeatRequestsRequest $request,
        ListMySeatRequests $list,
    ): JsonResponse {
        $page = $list(
            AuthContext::of($request)->account,
            $request->cursor(),
            $request->limit(),
        );

        return new JsonResponse(MySeatRequestPayload::page($page));
    }
}
