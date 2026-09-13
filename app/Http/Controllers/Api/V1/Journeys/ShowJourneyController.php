<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Journeys;

use App\Auth\AuthContext;
use App\Http\Responses\JourneyPayload;
use App\Journeys\ReadJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/routes/{routeId}/journeys/{serviceDate}`.
 *
 * The journey alone, with no envelope. A page has one because it carries a
 * cursor beside its rows; a single addressed resource has nothing to sit
 * beside, and wrapping it would be a key every client unwraps.
 *
 * Every refusal is `ReadJourney`'s single 404. Nothing is validated here first:
 * a 422 on the date would answer differently for "not a date" and "not your
 * route", which is a difference worth not telling anyone.
 */
final class ShowJourneyController
{
    public function __invoke(
        Request $request,
        ReadJourney $read,
        string $routeId,
        string $serviceDate,
    ): JsonResponse {
        $driver = AuthContext::of($request)->account;

        return new JsonResponse(
            JourneyPayload::from($read($driver, $routeId, $serviceDate)),
        );
    }
}
