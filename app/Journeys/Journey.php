<?php

declare(strict_types=1);

namespace App\Journeys;

use App\Models\Route;
use App\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * One dated journey: a route on a service date, and whatever has been recorded
 * about it being made.
 *
 * THE OCCURRENCE IS STILL A VALUE
 *
 * There is no `route_occurrences` table and this class is not a stand-in for
 * one. It is assembled from a route row, a date the caller named or the query
 * derived, and the trip row for that pair if there is one — nothing about it is
 * stored, and nothing has to be conjured before a driver can read it.
 *
 * `$trip` is null for a journey nobody has started, which is the ordinary case
 * rather than missing data. `App\Trips\TripLifecycle` is what turns that
 * absence into `not_started`, here as everywhere else.
 */
final readonly class Journey
{
    public function __construct(
        public Route $route,
        public CarbonImmutable $serviceDate,
        public ?Trip $trip,
    ) {}
}
