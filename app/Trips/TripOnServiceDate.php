<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which of a route's journeys a loaded relation holds, by date.
 *
 * WHY THIS EXISTS RATHER THAN `first()`
 *
 * A route is a plan and a journey is that plan on a date, so a route's trips
 * are a collection and taking the first of them would be choosing one. This
 * names the journey instead. `unique (route_id, service_date)` means at most
 * one row can match, so the answer is exact rather than a pick.
 *
 * WHY IN MEMORY
 *
 * The projections render a page of rows from an eager-loaded relation, and a
 * query per row would turn one read into N. The date is applied to what is
 * already loaded. Since Phase 16b that collection may hold several trips —
 * one per date a recurring plan has been driven on — so naming the date is the
 * whole of the method rather than a formality over a collection of one.
 *
 * NULL NO LONGER MEANS TWO THINGS
 *
 * It did: a journey that was never made, and a plan with no single journey to
 * look for, both rendered `not_started`. Phase 16b separated them. Every caller
 * now arrives with a date in hand — `MyRoutePayload` asks only for a one-off
 * route's own day, `MySeatRequestPayload` for the day the asking is about, and
 * `App\Reviews\SubmitReview` for the day the seat request names — so null here
 * means exactly one thing again, a journey nobody started. A plan's `trip` is
 * null on that surface without consulting this at all, and its dated journeys
 * are addressed through `App\Journeys` instead.
 */
final class TripOnServiceDate
{
    /**
     * @param  Collection<int, Trip>  $trips
     */
    public static function in(Collection $trips, ?CarbonImmutable $serviceDate): ?Trip
    {
        if ($serviceDate === null) {
            return null;
        }

        $wanted = $serviceDate->toDateString();

        return $trips->first(
            static fn (Trip $trip): bool => $trip->service_date->toDateString() === $wanted,
        );
    }
}
