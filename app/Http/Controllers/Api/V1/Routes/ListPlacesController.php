<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Routes;

use App\Http\Responses\PlacePayload;
use App\Models\Place;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/places`.
 *
 * The catalogue a driver picks endpoints from. Ordered by label so the picker
 * is stable between requests — a list that reshuffles itself is a list nobody
 * can learn.
 *
 * Not paginated, and that is a decision rather than an omission: this is
 * bounded reference data, short by design, and a cursor over it would be
 * ceremony. The first paginated response in this API is a member's own routes.
 *
 * Readable by any signed-in member. There is nothing personal here — these are
 * public meeting points — but it sits behind the credential anyway, because the
 * pilot's supported places are not something an unauthenticated caller needs.
 */
final class ListPlacesController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(PlacePayload::catalogue(
            Place::query()->orderBy('label')->get(),
        ));
    }
}
