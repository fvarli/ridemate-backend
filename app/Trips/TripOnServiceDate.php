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
 * already loaded. While both recurrence guards stand, that collection holds at
 * most one trip per route anyway.
 *
 * NULL MEANS TWO THINGS, AND THE SURFACES HAVE ALWAYS CONFLATED THEM
 *
 * A journey that was never made, and a plan that has no single journey to look
 * for. Both render `not_started`, which is what `MyRoute` has published for a
 * weekday plan since Phase 14. Phase 16b separates them: a plan stops carrying
 * a trip at all, and its dated journeys are addressed on their own.
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
