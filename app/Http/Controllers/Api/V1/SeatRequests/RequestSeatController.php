<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Requests\RequestSeatRequest;
use App\Http\Responses\MySeatRequestPayload;
use App\SeatRequests\RequestSeat;
use App\SeatRequests\SeatRequestViews;
use App\SeatRequests\ServiceDateRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

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
 * chooses the success code and one other thing: that a service date the route
 * cannot honour is a validation failure on its field rather than a refusal.
 */
final class RequestSeatController
{
    public function __invoke(
        RequestSeatRequest $request,
        string $routeId,
        RequestSeat $ask,
        SeatRequestViews $views,
    ): JsonResponse {
        try {
            $result = $ask(
                AuthContext::of($request)->account,
                $request->seatRequestId(),
                $routeId,
                $request->serviceDate(),
            );
        } catch (ServiceDateRefused $malformed) {
            // Not a refusal on the wire. A day this route never runs on, or one
            // past the horizon, is a value the client's own picker could have
            // ruled out — so it is a `422` on the field that carried it rather
            // than a conflict reason nobody can usefully branch on. Same shape
            // as publication's `InvalidJourney`.
            throw ValidationException::withMessages([
                'service_date' => [$malformed->getMessage()],
            ]);
        }

        return new JsonResponse(
            MySeatRequestPayload::envelope($views->own($result->request)),
            $result->wasAlreadyRequested ? 200 : 201,
        );
    }
}
