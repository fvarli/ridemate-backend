<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Responses\MySeatRequestPayload;
use App\SeatRequests\SeatRequestViews;
use App\SeatRequests\WithdrawSeatRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/seat-requests/{requestId}/withdraw`
 *
 * No body, no `expected_status`, no key: the transition names its own target
 * state, so repeating it is the same withdrawal observed again and answers
 * `200` with the request unchanged. Tier 2 in `docs/api-conventions.md`.
 */
final class WithdrawSeatRequestController
{
    public function __invoke(
        Request $request,
        string $requestId,
        WithdrawSeatRequest $withdraw,
        SeatRequestViews $views,
    ): JsonResponse {
        $result = $withdraw(AuthContext::of($request)->account, $requestId);

        return new JsonResponse(
            MySeatRequestPayload::envelope($views->own($result->request)),
        );
    }
}
