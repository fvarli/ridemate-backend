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
 * `CompleteTrip` gives at length — including that a withdrawn route and a
 * calendar day that has ended neither of them block this. A driver whose plan
 * was cancelled while they were mid-journey still has to be able to say the
 * journey was abandoned, and a journey that ran past midnight is abandoned on
 * the day it is abandoned rather than on the day it was for.
 */
final class AbortTrip
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
