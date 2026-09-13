<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Route;
use App\Routes\Recurrence;
use App\Trips\TripLifecycle;
use App\Trips\TripOnServiceDate;
use Carbon\CarbonImmutable;

/**
 * A journey as its own driver sees it in My Routes.
 *
 * Everything `RoutePayload` publishes, plus whether the journey was actually
 * made.
 *
 * WHY THIS IS NOT A FLAG ON `RoutePayload`
 *
 * That class is also the publish and the cancel response, and Phase 14 put the
 * lifecycle on exactly two surfaces: this one and a passenger's own seat
 * requests. Adding a field there widened three endpoints to serve two, which is
 * how a shared payload starts meaning different things at different call sites
 * — and an optional-field switch would only move that ambiguity into a
 * parameter. Composition keeps the base projection exactly what it was and puts
 * the extra fact where it was asked for.
 *
 * Owner-only by construction: nothing outside My Routes builds this.
 */
final class MyRoutePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Route $route, ?CarbonImmutable $now = null): array
    {
        return RoutePayload::from($route, $now) + [
            // NULL FOR A PLAN, AND THAT IS NOT `not_started`.
            //
            // A one-off route is its own single journey, so the lifecycle is
            // the route's and this is what it has always been. A recurring plan
            // is not a journey at all: it has as many as it has dates, each
            // with its own state, and no one of them is the plan's. Saying
            // `not_started` — which this surface did until Phase 16b — was a
            // claim about a journey that does not exist, and it stayed wrong
            // while a driver was mid-trip on Tuesday.
            //
            // Null says the question does not apply here. The dated journeys
            // are read from `GET /api/v1/me/journeys` and
            // `GET /api/v1/routes/{routeId}/journeys/{serviceDate}`, which
            // answer it per date. Nothing may pick one of the plan's trips to
            // fill this in: whichever it picked would be a date the caller
            // never named.
            'trip' => $route->recurrence === Recurrence::Once
                ? TripPayload::from(TripLifecycle::of(
                    TripOnServiceDate::in($route->trips, $route->departure_date),
                ))
                : null,
        ];
    }
}
