<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Requests\RequestSeatRequest;
use App\Http\Responses\MySeatRequestPayload;
use App\SeatRequests\RequestSeat;
use App\SeatRequests\SeatRequestViews;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/routes/{routeId}/seat-requests`
 *
 * `201` for a new asking, `200` for a retry of one that already landed — the
 * same distinction publication draws, for the same reason: the client's id is
 * the idempotency key, so a retry after a lost response is the same asking
 * arriving twice rather than a second one.
 *
 * Every refusal is raised by the domain and rendered by `ExceptionRenderer`,
 * which is where the status and `details.reason` are decided. This controller
 * chooses nothing but the success code.
 */
final class RequestSeatController
{
    public function __invoke(
        RequestSeatRequest $request,
        string $routeId,
        RequestSeat $ask,
        SeatRequestViews $views,
    ): JsonResponse {
        $result = $ask(
            AuthContext::of($request)->account,
            $request->seatRequestId(),
            $routeId,
        );

        return new JsonResponse(
            MySeatRequestPayload::envelope($views->own($result->request)),
            $result->wasAlreadyRequested ? 200 : 201,
        );
    }
}
