<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Account;
use App\Models\Route;
use App\Models\Trip;
use App\Routes\Recurrence;
use App\Routes\RouteDeparture;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A driver says the journey on a given day is under way.
 *
 * THE EXISTING TRIP IS RESOLVED BEFORE ANYTHING MUTABLE
 *
 * Start names a single target state, so repeating it is that start observed
 * again rather than a second one — and a repeat must keep succeeding however
 * the world has moved on since. Checking eligibility first would make a command
 * that already succeeded begin to fail later, which is exactly the defect the
 * create path's identity-before-eligibility ordering exists to prevent in
 * `App\SeatRequests\RequestSeat`.
 *
 * Phase 16b gives that rule teeth it did not have. A recurring journey started
 * at 08:25 on Tuesday is still `in_progress` at one in the morning on
 * Wednesday, and Tuesday's calendar day is over — so a Start replayed then
 * would answer `service_date_passed` for a journey the driver is on, if
 * eligibility ran first. It does not. The exact `(route_id, service_date)` trip
 * is read first and returned, and the clock is never consulted for it.
 *
 * Only a journey with no trip reaches the eligibility checks at all.
 *
 * THE ROUTE ROW IS THE SERIALIZATION POINT
 *
 * Locked first, and the trip is read inside that lock. Two devices pressing
 * Start at once therefore serialize: the second finds the first's trip and
 * answers with it. `unique (route_id, service_date)` stays as defence, not as
 * control flow — a domain that relied on catching it would be using the
 * database to decide something it could have decided itself.
 *
 * Authorization, the lock and the lock order live in `OwnedRoute`, which every
 * trip command shares so the rule cannot differ between them.
 *
 * WHAT `in_progress` DOES NOT MEAN
 *
 * That the driver is at the origin, that the vehicle is moving, that anybody
 * boarded, or that any location is known. There are no coordinates, no geofence
 * and no routing anywhere in this product. It means one thing: this command was
 * accepted for this dated journey.
 */
final class StartTrip
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
    ): StartedTrip {
        return DB::transaction(function () use ($driver, $routeId, $serviceDate, $now): StartedTrip {
            $route = $this->routes->lock($driver, $routeId);
            // Which journey, decided before anything is read about it and
            // before any clock is consulted. A day the route does not run is a
            // 404 from here; the older form aimed at a plan is refused here too.
            $journey = CommandedJourney::dateFor($route, $serviceDate);

            $existing = $this->routes->tripOn($route, $journey);

            if ($existing instanceof Trip) {
                // Deliberately terminal: no recurrence check, no availability
                // check, no clock. See the note above.
                return $this->resolveExisting($existing);
            }

            $this->refuseIneligible($route, $journey, $now);

            return new StartedTrip($this->create($route, $journey, $now), wasAlreadyStarted: false);
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
     * Reached only when no trip exists for the day, so everything below is
     * about creating one rather than about a journey already under way.
     *
     * THE WINDOW IS A DAY WIDE FOR A PLAN, AND OPEN-ENDED FOR A ONE-OFF
     *
     * A plan's journey belongs to one calendar date in the route's own zone. It
     * may be started from its departure instant until that date ends there —
     * before, `departure_not_reached`; after, `service_date_passed`, because
     * Wednesday morning is a different journey with its own command.
     *
     * A one-off route keeps Phase 14's behaviour exactly: once its departure is
     * past it can be started, with no upper bound at all. The asymmetry is
     * deliberate. A one-off journey has no next occurrence for a late Start to
     * be confused with, and narrowing it would take away something drivers have
     * today to make two unlike things look alike.
     */
    private function refuseIneligible(Route $route, CarbonImmutable $journey, ?CarbonImmutable $now): void
    {
        if (! $route->isPublished()) {
            throw TripRefused::routeUnavailable();
        }

        $departure = $route->departure();
        $now ??= CarbonImmutable::now();

        // Reused rather than recomputed: the departure's own timezone, its own
        // wall clock, and one implementation of what "has it left yet" means.
        // There is no grace window — an early start would be the server
        // agreeing to something that has not happened.
        if (! $departure->hasDeparted($journey, $now)) {
            throw TripRefused::departureNotReached();
        }

        if ($route->recurrence !== Recurrence::Once && $this->dayIsOver($departure, $journey, $now)) {
            throw TripRefused::serviceDatePassed();
        }

        // Nothing counts accepted seat requests. A driver making the journey
        // alone is making the journey, and requiring a passenger would let an
        // empty car block a departure that is happening anyway.
    }

    /**
     * Has the route's own calendar moved past this journey's day?
     *
     * Read where the route is, never where the process is: a deployment three
     * hours from the pilot would otherwise end a driver's day early or late,
     * and the mistake would be invisible while every route shares one zone.
     */
    private function dayIsOver(
        RouteDeparture $departure,
        CarbonImmutable $journey,
        CarbonImmutable $now,
    ): bool {
        return $departure->localDate($now)->toDateString() !== $journey->toDateString();
    }

    private function create(Route $route, CarbonImmutable $journey, ?CarbonImmutable $now): Trip
    {
        $trip = new Trip;
        // Server-generated, unlike a route or a seat request: there is no
        // client id to be idempotent on here, because the route and the date
        // together identify the journey and `unique (route_id, service_date)`
        // says so.
        $trip->route_id = $route->id;
        // The journey this command named, or the one-off route's own day —
        // resolved and checked above, never guessed here.
        $trip->service_date = $journey;
        $trip->status = TripStatus::InProgress;
        $trip->started_at = $now ?? CarbonImmutable::now();
        $trip->save();

        return $trip;
    }
}
