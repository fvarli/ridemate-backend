<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Responses\RouteSeatRequestPayload;
use App\SeatRequests\AcceptSeatRequest;
use App\SeatRequests\SeatRequestViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/seat-requests/{requestId}/accept`
 *
 * The one command that binds a seat. Capacity and the journey's state are the
 * domain's to check, inside the route lock; a repeated accept of a request
 * that is already accepted answers `200` unchanged even after the journey was
 * cancelled or filled, because the seat was already given.
 */
final class AcceptSeatRequestController
{
    public function __invoke(
        Request $request,
        string $requestId,
        AcceptSeatRequest $accept,
        SeatRequestViews $views,
    ): JsonResponse {
        $result = $accept(AuthContext::of($request)->account, $requestId);

        return new JsonResponse(
            RouteSeatRequestPayload::envelope($views->incoming($result->request)),
        );
    }
}
