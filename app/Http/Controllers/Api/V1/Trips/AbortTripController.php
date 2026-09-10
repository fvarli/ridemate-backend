<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trips;

use App\Auth\AuthContext;
use App\Http\Responses\TripPayload;
use App\Trips\AbortTrip;
use App\Trips\TripLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/routes/{routeId}/trip/abort`
 *
 * Always `200`, and a repeat leaves `aborted_at` where it was. Mirrors
 * completion exactly, because the two are the same shape of decision about the
 * same resource.
 *
 * No reason is accepted or stored. A taxonomy of why a journey was abandoned
 * would be a design nobody has made, and a free-text field would be somewhere
 * a member could write anything about somebody else.
 */
final class AbortTripController
{
    public function __invoke(Request $request, string $routeId, AbortTrip $abort): JsonResponse
    {
        $result = $abort(AuthContext::of($request)->account, $routeId);

        return new JsonResponse(TripPayload::envelope(TripLifecycle::of($result->trip)));
    }
}
