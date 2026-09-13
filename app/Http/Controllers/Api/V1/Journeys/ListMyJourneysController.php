<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Journeys;

use App\Auth\AuthContext;
use App\Http\Requests\ListMyJourneysRequest;
use App\Http\Responses\JourneyPayload;
use App\Journeys\ListMyJourneys;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/me/journeys`.
 *
 * Four lines of work, and that is the point: which journeys are included, how a
 * page is filled and what the cursor means are all decided in App\Journeys, so
 * they read the same way whether they are reached from HTTP or from anywhere
 * else.
 *
 * The driver comes from the credential. There is no parameter naming a member —
 * one would let a caller ask what somebody else's day looks like.
 */
final class ListMyJourneysController
{
    public function __invoke(ListMyJourneysRequest $request, ListMyJourneys $journeys): JsonResponse
    {
        $driver = AuthContext::of($request)->account;

        return new JsonResponse(JourneyPayload::page(
            $journeys($driver, $request->cursor(), $request->limit()),
        ));
    }
}
