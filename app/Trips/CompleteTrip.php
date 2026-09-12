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
 * Recurrence, publication state, the departure instant and who accepted a seat
 * are creation concerns, answered when the trip was started. Asking them again
 * here would let a journey that is demonstrably under way become impossible to
 * finish because something about its route changed afterwards — and a driver
 * stuck with a permanently running trip has no way out that is honest.
 *
 * `RouteDeparture::instant()` is deliberately not called.
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

    public function __invoke(
        Account $driver,
        string $routeId,
        ?CarbonImmutable $now = null,
    ): EndedTrip {
        return DB::transaction(function () use ($driver, $routeId, $now): EndedTrip {
            $route = $this->routes->lock($driver, $routeId);
            $trip = $this->routes->tripOn($route, $route->departure_date);

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
