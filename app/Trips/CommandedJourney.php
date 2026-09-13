<?php

declare(strict_types=1);

namespace App\Trips;

use App\Journeys\ServiceDate;
use App\Models\Route;
use App\Routes\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Which dated journey a trip command was aimed at.
 *
 * TWO WAYS TO ADDRESS ONE THING
 *
 * A driver reaches a journey either by naming the day —
 * `/routes/{routeId}/journeys/{serviceDate}/trip/...` — or through the older
 * route-only endpoints, which name no day at all. Both arrive here, so Start,
 * Complete and Abort cannot come to disagree about what either form means.
 *
 * THE OLDER FORM IS FOR ONE-OFF JOURNEYS, AND STAYS THAT WAY
 *
 * A one-off route has a single day and its own `departure_date` is it, so an
 * older client that names nothing still addresses exactly one journey. A plan
 * has many, and there is no honest way to pick: "today" would silently make a
 * request about a Tuesday into a command about a Wednesday, and "the next one"
 * would invent a schedule. So the older form refuses a plan outright, as it
 * always has, and a client that wants a plan's journey names the day.
 *
 * That refusal is `recurring_route_unsupported` for all three commands now.
 * Complete and Abort used to answer `trip_not_started`, which was true by
 * accident — a plan carried no date, so the lookup found nothing — and would
 * have become false the moment a plan's journey could be started. The reason a
 * client is told is now the reason that is actually true: this endpoint cannot
 * address a plan.
 *
 * A DAY THAT IS NOT A JOURNEY IS A MISS, NOT A REFUSAL
 *
 * `2026-02-30`, or a Saturday on a weekday plan, or any day but its own on a
 * one-off route: none of these is a journey the route has, so none of them has
 * a state to report. They answer 404, the same as a route that is not the
 * caller's — `docs/api-conventions.md`, and telling them apart would say which
 * routes exist and which days a stranger's plan runs on.
 */
final class CommandedJourney
{
    private function __construct() {}

    /**
     * @param  ?string  $serviceDate  the day named in the path, or null from the
     *                                older route-only endpoints
     *
     * @throws TripRefused when the older form is aimed at a plan.
     * @throws ModelNotFoundException when the day is not one this route runs.
     */
    public static function dateFor(Route $route, ?string $serviceDate): CarbonImmutable
    {
        if ($serviceDate === null) {
            if ($route->recurrence !== Recurrence::Once) {
                throw TripRefused::recurringRouteUnsupported();
            }

            return $route->soleServiceDate();
        }

        $day = ServiceDate::parse($serviceDate);

        // The route's own recurrence decides, in the one place that owns it.
        if (! $day instanceof CarbonImmutable || ! $route->runsOn($day)) {
            throw (new ModelNotFoundException)->setModel(Route::class, [$route->id]);
        }

        return $day;
    }
}
