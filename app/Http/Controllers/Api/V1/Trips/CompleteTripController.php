<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trips;

use App\Auth\AuthContext;
use App\Http\Responses\TripPayload;
use App\Trips\CompleteTrip;
use App\Trips\TripLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/routes/{routeId}/trip/complete`
 *
 * Always `200`. Nothing is created — the trip already exists, and this names
 * the state it should end in, so running it twice is that ending observed
 * again with `completed_at` untouched. Inventing a `201` here would say a
 * second resource appeared, and none did.
 *
 * No body, no `expected_status`, no key: tier 2 in `docs/api-conventions.md`.
 */
final class CompleteTripController
{
    public function __invoke(Request $request, string $routeId, CompleteTrip $complete): JsonResponse
    {
        $result = $complete(AuthContext::of($request)->account, $routeId);

        return new JsonResponse(TripPayload::envelope(TripLifecycle::of($result->trip)));
    }
}
