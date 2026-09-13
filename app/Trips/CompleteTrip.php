<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Account;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A driver says the journey was made.
 *
 * A TERMINAL STATE IS IMMUTABLE
 *
 * Completing something already completed is that completion observed again:
 * the stored `completed_at` is returned untouched, because a repeat is not a
 * second ending. Completing something aborted is not a repeat at all — it is a
 * different claim about what happened — so it is refused rather than allowed to
 * overwrite the record.
 *
 * ELIGIBILITY IS NOT RE-RUN
 *
 * Publication state, the departure instant and who accepted a seat are creation
 * concerns, answered when the trip was started. Asking them again here would
 * let a journey that is demonstrably under way become impossible to finish
 * because something about its route changed afterwards — and a driver stuck
 * with a permanently running trip has no way out that is honest.
 *
 * So a withdrawn route does not block this, and neither does the calendar:
 * `RouteDeparture::instant()` is not called, and a plan's journey started on
 * Tuesday is still completable on Wednesday. Start bounds itself to one
 * calendar day because it CREATES something dated; ending a thing that already
 * exists has no such day to be inside.
 *
 * The one thing that is decided here is WHICH journey — `CommandedJourney`,
 * shared with Start so the three commands cannot read an address differently.
 *
 * WHAT `completed` DOES NOT MEAN
 *
 * That anybody boarded, that anywhere was reached, that any distance was
 * travelled, that anything is owed, or that anybody may now be reviewed. It
 * means an authorized driver said the journey was made and the server recorded
 * it. Review eligibility is Phase 15's, and nothing here anticipates it.
 *
 * Seat requests are untouched: Phase 13's four states are the passenger's own
 * history, and a journey ending is not an answer to anybody's asking.
 */
final class CompleteTrip
{
    public function __construct(private readonly OwnedRoute $routes) {}

    /**
     * @param  ?string  $serviceDate  the day named in the path, or null from the
     *                                older route-only endpoint
     */
    public function __invoke(
        Account $driver,
        string $routeId,
        ?string $serviceDate = null,
        ?CarbonImmutable $now = null,
    ): EndedTrip {
        return DB::transaction(function () use ($driver, $routeId, $serviceDate, $now): EndedTrip {
            $route = $this->routes->lock($driver, $routeId);
            // Which journey, before anything is read about it. Exact: a trip on
            // another date of the same plan is a different journey and must not
            // answer for this one.
            $trip = $this->routes->tripOn($route, CommandedJourney::dateFor($route, $serviceDate));

            if (! $trip instanceof Trip) {
                throw TripRefused::tripNotStarted();
            }

            return match ($trip->status) {
                TripStatus::InProgress => $this->complete($trip, $now),
                // Already there. Nothing is written, not even a timestamp.
                TripStatus::Completed => new EndedTrip($trip, wasAlreadyEnded: true),
                TripStatus::Aborted => throw TripRefused::alreadyAborted(),
            };
        });
    }

    private function complete(Trip $trip, ?CarbonImmutable $now): EndedTrip
    {
        $trip->status = TripStatus::Completed;
        // `started_at` is left exactly as it was: when the journey began is not
        // something finishing it revises.
        $trip->completed_at = $now ?? CarbonImmutable::now();
        $trip->save();

        return new EndedTrip($trip, wasAlreadyEnded: false);
    }
}
