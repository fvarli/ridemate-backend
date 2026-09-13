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
 * `POST /api/v1/routes/{routeId}/journeys/{serviceDate}/trip/complete`
 * `POST /api/v1/routes/{routeId}/trip/complete`  (one-off journeys only)
 *
 * TWO PATHS, ONE COMMAND
 *
 * The dated form names the journey outright, which is the only honest way to
 * address one day of a plan that has many. The older form names no day and is
 * kept for clients that predate them: it resolves a one-off route's single
 * journey and refuses a plan with `recurring_route_unsupported`, because
 * picking "today" for a caller who said nothing would answer about a journey
 * they did not name. `App\Trips\CommandedJourney` decides both, so the two
 * forms cannot drift.
 *
 * `$serviceDate` is null on the older route because that path declares no such
 * parameter — the absence IS the older form, and nothing here has to be told
 * which route it is serving.
 *
 * This controller chooses nothing but the status code. Whether the command is
 * allowed is the domain's, decided inside the route lock; a refusal is raised
 * there and rendered by `ExceptionRenderer`, which is where the status and
 * `details.reason` are decided. A route that is not the caller's, and a day the
 * route does not run on, both raise `ModelNotFoundException` and answer 404 —
 * the same as a route that does not exist, so a member cannot learn which
 * journeys are real by trying to command them.
 *
 * Always `200`. Nothing is created — the trip already exists, and this names
 * the state it should end in, so running it twice is that ending observed again
 * with `completed_at` untouched. Inventing a `201` here would say a second
 * resource appeared, and none did.
 *
 * No body, no `expected_status`, no key: tier 2 in `docs/api-conventions.md`.
 */
final class CompleteTripController
{
    public function __invoke(
        Request $request,
        string $routeId,
        CompleteTrip $complete,
        ?string $serviceDate = null,
    ): JsonResponse {
        $result = $complete(AuthContext::of($request)->account, $routeId, $serviceDate);

        return new JsonResponse(TripPayload::envelope(TripLifecycle::of($result->trip)));
    }
}
