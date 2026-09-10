<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Account;
use App\Models\Route;
use App\Models\Trip;
use App\Routes\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * A driver says the journey is under way.
 *
 * THE EXISTING TRIP IS RESOLVED BEFORE ANYTHING MUTABLE
 *
 * Start names a single target state, so repeating it is that start observed
 * again rather than a second one — and a repeat must keep succeeding however
 * the world has moved on since. Checking route eligibility first would make a
 * command that already succeeded begin to fail later, which is exactly the
 * defect the create path's identity-before-eligibility ordering exists to
 * prevent in `App\SeatRequests\RequestSeat`.
 *
 * Only a route with no trip reaches the eligibility checks at all.
 *
 * THE ROUTE ROW IS THE SERIALIZATION POINT
 *
 * Locked first, and the trip is read inside that lock. Two devices pressing
 * Start at once therefore serialize: the second finds the first's trip and
 * answers with it. The `unique (route_id)` constraint stays as defence, not as
 * control flow — a domain that relied on catching it would be using the
 * database to decide something it could have decided itself.
 *
 * AUTHORIZATION IS IN THE QUERY
 *
 * The route is fetched scoped to its owner, so a driver asking about somebody
 * else's journey gets the same answer as one asking about a journey that does
 * not exist. `docs/api-conventions.md`: distinguishing them says the resource
 * is real.
 *
 * LOCK ORDER
 *
 * This command locks the route and nothing else. Accept (Phase 13) locks a seat
 * request then a route; nothing anywhere locks a route then a seat request, so
 * `request → route` remains the only two-resource order in the system and a
 * cycle stays unreachable.
 *
 * WHAT `in_progress` DOES NOT MEAN
 *
 * That the driver is at the origin, that the vehicle is moving, that anybody
 * boarded, or that any location is known. Phase 14 has no coordinates, no
 * geofence and no routing. It means one thing: this command was accepted.
 */
final class StartTrip
{
    public function __invoke(
        Account $driver,
        string $routeId,
        ?CarbonImmutable $now = null,
    ): StartedTrip {
        return DB::transaction(function () use ($driver, $routeId, $now): StartedTrip {
            $route = Route::query()
                ->where('account_id', $driver->id)
                ->lockForUpdate()
                ->find($routeId);

            if (! $route instanceof Route) {
                throw (new ModelNotFoundException)->setModel(Route::class, [$routeId]);
            }

            $existing = $route->trip()->first();

            if ($existing instanceof Trip) {
                // Deliberately terminal: no recurrence check, no availability
                // check, no clock. See the note above.
                return $this->resolveExisting($existing);
            }

            $this->refuseIneligible($route, $now);

            return new StartedTrip($this->create($route, $now), wasAlreadyStarted: false);
        });
    }

    /**
     * What an existing trip means for a repeated Start.
     *
     * A journey already under way is the state this command wanted, so it is
     * returned unchanged — nothing is written, not even a timestamp, because a
     * repeat is one start observed twice rather than a second event. A journey
     * that has ended cannot be started again: that is not a repeat, it is a
     * different request.
     */
    private function resolveExisting(Trip $existing): StartedTrip
    {
        return match ($existing->status) {
            TripStatus::InProgress => new StartedTrip(
                $existing,
                wasAlreadyStarted: true,
            ),
            TripStatus::Completed => throw TripRefused::alreadyCompleted(),
            TripStatus::Aborted => throw TripRefused::alreadyAborted(),
        };
    }

    /**
     * Why this journey cannot be started, if it cannot.
     *
     * THE ORDER IS LOAD-BEARING. Recurrence comes first because a recurring
     * route has no departure instant at all — `RouteDeparture::state` calls it
     * upcoming for ever — so a clock check placed above it would answer
     * `departure_not_reached` for every weekday plan and turn
     * `recurring_route_unsupported` into a reason nothing can produce.
     */
    private function refuseIneligible(Route $route, ?CarbonImmutable $now): void
    {
        if ($route->recurrence !== Recurrence::Once) {
            throw TripRefused::recurringRouteUnsupported();
        }

        if (! $route->isPublished()) {
            throw TripRefused::routeUnavailable();
        }

        // Reused rather than recomputed: the departure's own timezone, its own
        // wall clock, and one implementation of what "has it left yet" means.
        // There is no grace window — an early start would be the server
        // agreeing to something that has not happened.
        $instant = $route->departure()->instant();

        if ($instant === null || $instant->greaterThan($now ?? CarbonImmutable::now())) {
            throw TripRefused::departureNotReached();
        }

        // Nothing counts accepted seat requests. A driver making the journey
        // alone is making the journey, and requiring a passenger would let an
        // empty car block a departure that is happening anyway.
    }

    private function create(Route $route, ?CarbonImmutable $now): Trip
    {
        $trip = new Trip;
        // Server-generated, unlike a route or a seat request: there is no
        // client id to be idempotent on here, because the route already
        // identifies the journey and `unique (route_id)` says so.
        $trip->route_id = $route->id;
        $trip->status = TripStatus::InProgress;
        $trip->started_at = $now ?? CarbonImmutable::now();
        $trip->save();

        return $trip;
    }
}
