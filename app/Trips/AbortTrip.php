<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Account;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A driver says the journey was not made after all.
 *
 * The mirror of `CompleteTrip`, and terminal in exactly the same way: aborting
 * something already aborted returns the stored `aborted_at` untouched, and
 * aborting something completed is refused rather than allowed to rewrite what
 * the driver already said.
 *
 * NO REASON IS RECORDED
 *
 * Phase 14 stores that a journey was abandoned and when. It does not store why.
 * A reason taxonomy — accident, no-show, emergency, vehicle, passenger — is a
 * product and safety decision nobody has made, and a dropdown invented here
 * would be answering it by accident. When one is designed it can be added; a
 * column filled with a guess cannot be un-guessed.
 *
 * `aborted` means an authorized driver said so. It does not mean anybody was
 * left waiting, that anything went wrong, or that anyone is at fault.
 *
 * Eligibility is not re-run and seat requests are untouched, for the reasons
 * `CompleteTrip` gives at length.
 */
final class AbortTrip
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
                TripStatus::InProgress => $this->abort($trip, $now),
                TripStatus::Aborted => new EndedTrip($trip, wasAlreadyEnded: true),
                TripStatus::Completed => throw TripRefused::alreadyCompleted(),
            };
        });
    }

    private function abort(Trip $trip, ?CarbonImmutable $now): EndedTrip
    {
        $trip->status = TripStatus::Aborted;
        $trip->aborted_at = $now ?? CarbonImmutable::now();
        $trip->save();

        return new EndedTrip($trip, wasAlreadyEnded: false);
    }
}
