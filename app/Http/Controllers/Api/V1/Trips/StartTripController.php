<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trips;

use App\Auth\AuthContext;
use App\Http\Responses\TripPayload;
use App\Trips\StartTrip;
use App\Trips\TripLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/routes/{routeId}/trip/start`
 *
 * `201` the first time, `200` for a repeat of one that already landed — the
 * same distinction publication and asking for a seat draw. Here the route is
 * the idempotency key rather than a client-generated id: a journey has at most
 * one trip and `unique (route_id)` says so, which is why this command needs no
 * body to be safe to retry.
 *
 * This controller chooses nothing but that code. Whether the journey may be
 * started at all is `StartTrip`'s, decided inside the route lock; a refusal is
 * raised there and rendered by `ExceptionRenderer`, which is where the status
 * and `details.reason` are decided. A route that is not the caller's raises
 * `ModelNotFoundException` from `OwnedRoute` and answers 404, the same as one
 * that does not exist — a member cannot learn that somebody else's journey is
 * real by trying to start it.
 */
final class StartTripController
{
    public function __invoke(Request $request, string $routeId, StartTrip $start): JsonResponse
    {
        $result = $start(AuthContext::of($request)->account, $routeId);

        return new JsonResponse(
            TripPayload::envelope(TripLifecycle::of($result->trip)),
            $result->wasAlreadyStarted ? 200 : 201,
        );
    }
}
