<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Models\Trip;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\CancelRoute;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use App\SeatRequests\AcceptSeatRequest;
use App\SeatRequests\RequestSeat;
use App\Trips\RefusalReason;
use App\Trips\StartedTrip;
use App\Trips\StartTrip;
use App\Trips\TripRefused;
use App\Trips\TripStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * Starting a journey, and the ordering that keeps a repeat honest.
 *
 * The command names one target state, so pressing Start twice is that start
 * observed again — and it must stay a success however the journey has changed
 * since. That is why the existing trip is resolved before anything mutable, and
 * most of this file is about proving the order rather than the outcome.
 */
final class StartTripTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private const KADIKOY = 'kadikoy-iskele';

    private const LEVENT = 'levent-metro';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    // -------------------------------------------------------------- starting

    public function test_a_driver_starts_their_journey_once_it_is_due(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        // Captured once: `departed()` reads the clock, so calling it twice
        // would compare two different instants.
        $at = $this->departed();

        $result = $this->start($driver, $route, at: $at);

        self::assertFalse($result->wasAlreadyStarted);
        self::assertSame(TripStatus::InProgress, $result->trip->status);
        self::assertSame($route->id, $result->trip->route_id);
        // Stamped by the domain, from the instant it was given. Compared to
        // the second because the model's datetime cast truncates microseconds
        // on assignment — the column's own resolution is not what is under
        // test here.
        self::assertSame(
            $at->format('Y-m-d H:i:s'),
            $result->trip->started_at->format('Y-m-d H:i:s'),
        );
        self::assertNull($result->trip->completed_at);
        self::assertNull($result->trip->aborted_at);
        self::assertSame(1, Trip::query()->count());
    }

    /** Exactly at the instant, not only after it. */
    public function test_the_departure_instant_itself_is_late_enough(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $instant = $route->departure()->instant();
        self::assertNotNull($instant);

        $result = $this->start($driver, $route, at: $instant);

        self::assertSame(TripStatus::InProgress, $result->trip->status);
    }

    /**
     * A driver travelling alone is still travelling.
     *
     * Requiring an accepted passenger would let an empty car block a departure
     * that is happening anyway — and the product does not exist to fill seats,
     * it exists to share journeys somebody is already making.
     */
    public function test_a_journey_with_nobody_aboard_still_starts(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        self::assertSame(0, DB::table('seat_requests')->count());

        $result = $this->start($driver, $route, at: $this->departed());

        self::assertSame(TripStatus::InProgress, $result->trip->status);
    }

    /** And an accepted passenger neither helps nor is consulted. */
    public function test_an_accepted_passenger_changes_nothing_about_starting(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $passenger = $this->passenger();

        app(RequestSeat::class)($passenger, $this->requestId(), $route->id);
        app(AcceptSeatRequest::class)($driver, $this->requestId());

        $result = $this->start($driver, $route, at: $this->departed());

        self::assertSame(TripStatus::InProgress, $result->trip->status);
        // The asking is untouched: starting is not an answer to anybody.
        self::assertSame(
            'accepted',
            DB::table('seat_requests')->where('id', $this->requestId())->value('status'),
        );
    }

    // ----------------------------------------------------------- refusals

    public function test_a_journey_that_has_not_left_yet_cannot_start(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $refused = $this->refusal($driver, $route, at: CarbonImmutable::now());

        self::assertSame(RefusalReason::DepartureNotReached, $refused->reason);
        self::assertSame(0, Trip::query()->count());
    }

    /**
     * A minute short is still short. There is no grace window, because any
     * number here would be one nobody chose.
     */
    public function test_a_minute_before_the_instant_is_still_too_early(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $instant = $route->departure()->instant();
        self::assertNotNull($instant);

        $refused = $this->refusal($driver, $route, at: $instant->subMinute());

        self::assertSame(RefusalReason::DepartureNotReached, $refused->reason);
    }

    /**
     * THE ORDERING THAT KEEPS A REASON REACHABLE.
     *
     * A recurring route has no departure instant, so its state is upcoming for
     * ever. If the clock were consulted before the recurrence, every weekday
     * plan would answer `departure_not_reached` and
     * `recurring_route_unsupported` would be a reason nothing could produce.
     */
    public function test_a_weekday_plan_is_refused_for_being_recurring(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, recurrence: Recurrence::Weekdays);

        // Far in the future, so a clock-first implementation would still say
        // the departure had not been reached.
        $refused = $this->refusal($driver, $route, at: CarbonImmutable::now()->addYear());

        self::assertSame(RefusalReason::RecurringRouteUnsupported, $refused->reason);
        self::assertNotSame(RefusalReason::DepartureNotReached, $refused->reason);
    }

    public function test_a_withdrawn_journey_cannot_be_made(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        app(CancelRoute::class)($driver, $route->id);

        $refused = $this->refusal($driver, $route, at: $this->departed());

        self::assertSame(RefusalReason::RouteUnavailable, $refused->reason);
        self::assertSame(0, Trip::query()->count());
    }

    // ------------------------------------------------------- authorization

    public function test_an_unknown_route_is_a_non_disclosing_miss(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(StartTrip::class)($this->driver(), $this->routeId('ff'));
    }

    /** Somebody else's journey answers exactly as one that does not exist. */
    public function test_another_drivers_route_is_the_same_miss(): void
    {
        $owner = $this->driver();
        $route = $this->route($owner);
        $stranger = $this->driver('+905321119999');

        $this->expectException(ModelNotFoundException::class);

        app(StartTrip::class)($stranger, $route->id, $this->departed());
    }

    // --------------------------------------------------------- repeating

    public function test_starting_again_returns_the_same_trip_unchanged(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $first = $this->start($driver, $route, at: $this->departed());
        $before = Trip::query()->sole();

        $second = $this->start($driver, $route, at: $this->departed()->addHour());
        $after = Trip::query()->sole();

        self::assertFalse($first->wasAlreadyStarted);
        self::assertTrue($second->wasAlreadyStarted);
        self::assertSame($first->trip->id, $second->trip->id);
        // Not even a timestamp: a repeat is one start observed twice.
        self::assertEquals($before->started_at, $after->started_at);
        self::assertEquals($before->updated_at, $after->updated_at);
        self::assertSame(1, Trip::query()->count());
    }

    /**
     * THE CASE THE ORDERING EXISTS FOR.
     *
     * A journey is under way and its route has since been withdrawn — which
     * only a console command or a later phase could do, but the point is that
     * the answer must not depend on it. Judging a repeat against current
     * eligibility would tell the driver their running trip had failed.
     */
    public function test_a_repeat_succeeds_even_if_the_route_is_no_longer_eligible(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $first = $this->start($driver, $route, at: $this->departed());

        // Written directly: the API cannot cancel a departed route, and this
        // test is about the ordering rather than about how the row got there.
        DB::table('routes')->where('id', $route->id)->update([
            'status' => 'cancelled',
            'cancelled_at' => CarbonImmutable::now(),
        ]);

        $repeat = $this->start($driver, $route, at: $this->departed()->addHour());

        self::assertTrue($repeat->wasAlreadyStarted);
        self::assertSame($first->trip->id, $repeat->trip->id);
    }

    public function test_a_completed_journey_cannot_be_started_again(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $this->start($driver, $route, at: $this->departed());
        $this->endTrip('completed');

        $refused = $this->refusal($driver, $route, at: $this->departed());

        self::assertSame(RefusalReason::AlreadyCompleted, $refused->reason);
    }

    public function test_an_aborted_journey_cannot_be_started_again(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $this->start($driver, $route, at: $this->departed());
        $this->endTrip('aborted');

        $refused = $this->refusal($driver, $route, at: $this->departed());

        self::assertSame(RefusalReason::AlreadyAborted, $refused->reason);
    }

    // ------------------------------------------------- structural proofs

    /**
     * The route is locked before its trip is read, and before anything is
     * written.
     *
     * That order is what makes two devices pressing Start converge on one trip:
     * the second waits on the row and then finds the first's. A true
     * two-connection race cannot be run under `RefreshDatabase` — the suite
     * holds one open transaction on one connection, so a competing transaction
     * would block rather than interleave — so the guarantee is proved
     * structurally, and no fake concurrency harness is built for it.
     *
     * The `unique (route_id)` constraint from B1 remains as defence; it is not
     * what this relies on.
     */
    public function test_the_route_is_locked_before_the_trip_is_read_or_written(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $statements = $this->statementsDuring(
            fn () => $this->start($driver, $route, at: $this->departed()),
        );

        $routeLock = $this->firstMatching(
            $statements,
            fn (string $s): bool => str_starts_with($s, 'select * from "routes"')
                && str_contains($s, 'for update'),
        );
        $tripRead = $this->firstMatching(
            $statements,
            fn (string $s): bool => str_contains($s, 'from "trips"'),
        );
        $insert = $this->firstMatching(
            $statements,
            fn (string $s): bool => str_contains($s, 'insert into "trips"'),
        );

        self::assertNotNull($routeLock, 'the route was read without a lock');
        self::assertNotNull($tripRead, 'the trip was never looked up');
        self::assertNotNull($insert, 'no trip was written');
        self::assertLessThan($tripRead, $routeLock, 'the trip was read before the lock');
        self::assertLessThan($insert, $routeLock, 'the write happened before the lock');
    }

    /** And nothing here ever locks a seat request, which would invert Phase 13's order. */
    public function test_starting_locks_no_seat_request(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $statements = $this->statementsDuring(
            fn () => $this->start($driver, $route, at: $this->departed()),
        );

        self::assertNull(
            $this->firstMatching(
                $statements,
                fn (string $s): bool => str_starts_with($s, 'select * from "seat_requests"')
                    && str_contains($s, 'for update'),
            ),
            'starting locked a seat request, inverting the request → route order',
        );
    }

    // ------------------------------------------------------------ fixtures

    private function start(
        Account $driver,
        Route $route,
        ?CarbonImmutable $at = null,
    ): StartedTrip {
        return app(StartTrip::class)($driver, $route->id, $at);
    }

    private function refusal(
        Account $driver,
        Route $route,
        ?CarbonImmutable $at = null,
    ): TripRefused {
        try {
            $this->start($driver, $route, at: $at);
        } catch (TripRefused $refused) {
            return $refused;
        }

        self::fail('the start was not refused');
    }

    /** An instant comfortably after the fixture route's departure. */
    private function departed(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(4);
    }

    /**
     * Ends the one trip directly.
     *
     * CompleteTrip and AbortTrip are B3's; this file must not depend on them.
     */
    private function endTrip(string $status): void
    {
        DB::table('trips')->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? CarbonImmutable::now() : null,
            'aborted_at' => $status === 'aborted' ? CarbonImmutable::now() : null,
        ]);
    }

    private function driver(string $phone = '+905321110000'): Account
    {
        $account = $this->createAccount($phone);
        (new SaveProfile)($account, DisplayName::fromInput('İrem Yılmaz'));

        return $account;
    }

    private function passenger(string $phone = '+905322220000'): Account
    {
        $account = $this->createAccount($phone);
        (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));

        return $account;
    }

    private function route(
        Account $driver,
        Recurrence $recurrence = Recurrence::Once,
    ): Route {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            $this->routeId('01'),
            $this->place(self::KADIKOY),
            $this->place(self::LEVENT),
            RouteDeparture::fromInput(
                $recurrence,
                $recurrence === Recurrence::Once
                    ? CarbonImmutable::now()->addDays(3)->format('Y-m-d')
                    : null,
                '08:25',
                $timezone,
            ),
            3,
            new RideRules(noSmoking: true, musicOk: false, noPets: false, quiet: false),
        )->route;
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    /** @return list<string> */
    private function statementsDuring(callable $command): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $command();
        DB::disableQueryLog();

        return array_values(array_map(
            static fn (array $entry): string => strtolower((string) $entry['query']),
            DB::getQueryLog(),
        ));
    }

    /**
     * @param  list<string>  $statements
     * @param  callable(string): bool  $matches
     */
    private function firstMatching(array $statements, callable $matches): ?int
    {
        foreach ($statements as $index => $statement) {
            if ($matches($statement)) {
                return $index;
            }
        }

        return null;
    }

    private function requestId(): string
    {
        return '01991d00-0000-7000-8000-000000000001';
    }

    private function routeId(string $tail): string
    {
        return '01991c00-0000-7000-8000-0000000000'.$tail;
    }
}
