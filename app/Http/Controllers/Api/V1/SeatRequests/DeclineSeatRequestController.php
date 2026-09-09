<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Responses\RouteSeatRequestPayload;
use App\SeatRequests\DeclineSeatRequest;
use App\SeatRequests\SeatRequestViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/seat-requests/{requestId}/decline`
 *
 * Permitted after the journey was cancelled or has departed: declining creates
 * no obligation and frees no seat, and forbidding it would strand pending
 * requests in a driver's list with no way to clear them.
 */
final class DeclineSeatRequestController
{
    public function __invoke(
        Request $request,
        string $requestId,
        DeclineSeatRequest $decline,
        SeatRequestViews $views,
    ): JsonResponse {
        $result = $decline(AuthContext::of($request)->account, $requestId);

        return new JsonResponse(
            RouteSeatRequestPayload::envelope($views->incoming($result->request)),
        );
    }
}
