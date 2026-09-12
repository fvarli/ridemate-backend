<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Models\Trip;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use App\SeatRequests\AcceptSeatRequest;
use App\SeatRequests\RequestSeat;
use App\Trips\AbortTrip;
use App\Trips\CompleteTrip;
use App\Trips\EndedTrip;
use App\Trips\RefusalReason;
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
 * Ending a journey, both ways.
 *
 * The invariant this file exists for is that a terminal state is immutable: the
 * same command again is a success that writes nothing, and the opposite command
 * is refused rather than allowed to rewrite what the driver already said.
 *
 * Nothing here re-checks the route. Recurrence, publication and the departure
 * instant were answered when the journey started, and asking them again would
 * let a trip that is demonstrably under way become impossible to finish.
 */
final class EndTripTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    // -------------------------------------------------------------- complete

    public function test_a_driver_reports_the_journey_was_made(): void
    {
        [$driver, $route] = $this->started();
        $before = Trip::query()->sole();
        $at = $this->departed()->addHour();

        $result = $this->complete($driver, $route, at: $at);

        self::assertFalse($result->wasAlreadyEnded);
        self::assertSame(TripStatus::Completed, $result->trip->status);
        self::assertSame($at->format('Y-m-d H:i:s'), $result->trip->completed_at?->format('Y-m-d H:i:s'));
        self::assertNull($result->trip->aborted_at);
        // Finishing does not revise when it began.
        self::assertEquals($before->started_at, $result->trip->started_at);
        self::assertSame($before->id, $result->trip->id);
        self::assertSame($route->id, $result->trip->route_id);
    }

    public function test_completing_twice_writes_nothing_the_second_time(): void
    {
        [$driver, $route] = $this->started();

        $first = $this->complete($driver, $route, at: $this->departed()->addHour());
        $before = Trip::query()->sole();

        $second = $this->complete($driver, $route, at: $this->departed()->addHours(5));
        $after = Trip::query()->sole();

        self::assertTrue($second->wasAlreadyEnded);
        self::assertSame($first->trip->id, $second->trip->id);
        // The stored instant, not the one this attempt would have written.
        self::assertEquals($before->completed_at, $after->completed_at);
        self::assertEquals($before->updated_at, $after->updated_at);
    }

    public function test_a_journey_nobody_started_cannot_be_completed(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $refused = $this->refusal(fn () => $this->complete($driver, $route));

        self::assertSame(RefusalReason::TripNotStarted, $refused->reason);
        self::assertSame(0, Trip::query()->count());
    }

    public function test_an_abandoned_journey_cannot_be_reported_as_made(): void
    {
        [$driver, $route] = $this->started();
        $this->abort($driver, $route, at: $this->departed()->addHour());
        $before = Trip::query()->sole();

        $refused = $this->refusal(fn () => $this->complete($driver, $route));

        self::assertSame(RefusalReason::AlreadyAborted, $refused->reason);

        $after = Trip::query()->sole();
        self::assertSame(TripStatus::Aborted, $after->status);
        self::assertNull($after->completed_at);
        self::assertEquals($before->aborted_at, $after->aborted_at);
    }

    // ----------------------------------------------------------------- abort

    public function test_a_driver_reports_the_journey_was_not_made(): void
    {
        [$driver, $route] = $this->started();
        $before = Trip::query()->sole();
        $at = $this->departed()->addHour();

        $result = $this->abort($driver, $route, at: $at);

        self::assertFalse($result->wasAlreadyEnded);
        self::assertSame(TripStatus::Aborted, $result->trip->status);
        self::assertSame($at->format('Y-m-d H:i:s'), $result->trip->aborted_at?->format('Y-m-d H:i:s'));
        self::assertNull($result->trip->completed_at);
        self::assertEquals($before->started_at, $result->trip->started_at);
        self::assertSame($before->id, $result->trip->id);
    }

    public function test_aborting_twice_writes_nothing_the_second_time(): void
    {
        [$driver, $route] = $this->started();

        $this->abort($driver, $route, at: $this->departed()->addHour());
        $before = Trip::query()->sole();

        $second = $this->abort($driver, $route, at: $this->departed()->addHours(5));
        $after = Trip::query()->sole();

        self::assertTrue($second->wasAlreadyEnded);
        self::assertEquals($before->aborted_at, $after->aborted_at);
        self::assertEquals($before->updated_at, $after->updated_at);
    }

    public function test_a_journey_nobody_started_cannot_be_aborted(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $refused = $this->refusal(fn () => $this->abort($driver, $route));

        self::assertSame(RefusalReason::TripNotStarted, $refused->reason);
    }

    public function test_a_completed_journey_cannot_be_abandoned(): void
    {
        [$driver, $route] = $this->started();
        $this->complete($driver, $route, at: $this->departed()->addHour());
        $before = Trip::query()->sole();

        $refused = $this->refusal(fn () => $this->abort($driver, $route));

        self::assertSame(RefusalReason::AlreadyCompleted, $refused->reason);

        $after = Trip::query()->sole();
        self::assertSame(TripStatus::Completed, $after->status);
        self::assertNull($after->aborted_at);
        self::assertEquals($before->completed_at, $after->completed_at);
    }

    // ---------------------------------------------------------- terminality

    /**
     * CARRIES WEIGHT. Whichever way a journey ended, it stays ended that way.
     *
     * The pair below is the whole state machine's promise: a driver cannot
     * change the record by pressing the other button afterwards.
     */
    public function test_neither_ending_can_be_overwritten_by_the_other(): void
    {
        [$completingDriver, $completed] = $this->started();
        $this->complete($completingDriver, $completed, at: $this->departed()->addHour());

        self::assertSame(
            RefusalReason::AlreadyCompleted,
            $this->refusal(fn () => $this->abort($completingDriver, $completed))->reason,
        );

        [$abortingDriver, $aborted] = $this->started(
            phone: '+905321112222',
            routeTail: '02',
        );
        $this->abort($abortingDriver, $aborted, at: $this->departed()->addHour());

        self::assertSame(
            RefusalReason::AlreadyAborted,
            $this->refusal(fn () => $this->complete($abortingDriver, $aborted))->reason,
        );
    }

    // -------------------------------------------------------- authorization

    public function test_an_unknown_route_is_a_non_disclosing_miss_for_both(): void
    {
        $driver = $this->driver();

        foreach (
            [
                fn () => app(CompleteTrip::class)($driver, $this->routeId('ff')),
                fn () => app(AbortTrip::class)($driver, $this->routeId('ff')),
            ] as $command
        ) {
            try {
                $command();
                self::fail('the command was not refused');
            } catch (ModelNotFoundException $miss) {
                // The answer an id nobody owns gets, and nothing more.
                self::assertStringNotContainsString('trip', strtolower($miss->getMessage()));
            }
        }
    }

    public function test_another_drivers_journey_is_the_same_miss_for_both(): void
    {
        [, $route] = $this->started();
        $stranger = $this->driver('+905321119999');

        foreach (
            [
                fn () => app(CompleteTrip::class)($stranger, $route->id),
                fn () => app(AbortTrip::class)($stranger, $route->id),
            ] as $command
        ) {
            try {
                $command();
                self::fail('the command was not refused');
            } catch (ModelNotFoundException $miss) {
                // Indistinguishable from a route that does not exist: the
                // message names the route model and says nothing about a trip.
                self::assertStringNotContainsString('trip', strtolower($miss->getMessage()));
            }
        }

        // And nothing moved.
        self::assertSame(TripStatus::InProgress, Trip::query()->sole()->status);
    }

    // --------------------------------------------------------- independence

    /**
     * A journey ending is not an answer to anybody's asking.
     *
     * Phase 13's four request states are the passenger's own history, and
     * Phase 14 introduces no `completed`, `expired`, `no_show` or `boarded` to
     * overwrite them with.
     */
    public function test_ending_a_journey_leaves_seat_requests_exactly_as_they_were(): void
    {
        [$driver, $route] = $this->started(withAcceptedPassenger: true);

        $before = DB::table('seat_requests')->where('id', $this->requestId())->first();
        self::assertNotNull($before);
        self::assertSame('accepted', $before->status);

        $this->complete($driver, $route, at: $this->departed()->addHour());

        $after = DB::table('seat_requests')->where('id', $this->requestId())->first();
        self::assertNotNull($after);
        self::assertSame('accepted', $after->status);
        self::assertEquals($before->decided_at, $after->decided_at);
        self::assertEquals($before->updated_at, $after->updated_at);
    }

    /**
     * Ending does not reconsider the route.
     *
     * Proved by the absence of the departure read: `RouteDeparture::instant()`
     * runs no query, but re-running creation eligibility would mean reading
     * the route's own columns again after the lock, and — more decisively — a
     * journey whose route was withdrawn afterwards can still be finished.
     */
    public function test_a_journey_can_be_finished_after_its_route_was_withdrawn(): void
    {
        [$driver, $route] = $this->started();

        // Written directly: the API cannot cancel a departed route, and this
        // is about the command's ordering rather than how the row got there.
        DB::table('routes')->where('id', $route->id)->update([
            'status' => 'cancelled',
            'cancelled_at' => CarbonImmutable::now(),
        ]);

        $result = $this->complete($driver, $route, at: $this->departed()->addHour());

        self::assertSame(TripStatus::Completed, $result->trip->status);
    }

    // ----------------------------------------------- serialization structure

    /**
     * The owner-scoped route is locked before the trip is read or written.
     *
     * Reported as serialization structure, not as a race: a true two-connection
     * test cannot run under `RefreshDatabase`, which holds one open transaction
     * on one connection, so a competing transaction would block rather than
     * interleave. No fake concurrency harness is built for it.
     */
    public function test_the_route_is_locked_before_the_trip_is_touched(): void
    {
        [$completingDriver, $completing] = $this->started();
        [$abortingDriver, $aborting] = $this->started(
            phone: '+905321112222',
            routeTail: '02',
        );

        foreach (
            [
                fn () => $this->complete(
                    $completingDriver,
                    $completing,
                    at: $this->departed()->addHour(),
                ),
                fn () => $this->abort(
                    $abortingDriver,
                    $aborting,
                    at: $this->departed()->addHour(),
                ),
            ] as $command
        ) {
            $statements = $this->statementsDuring($command);

            $lock = $this->firstMatching(
                $statements,
                fn (string $s): bool => str_starts_with($s, 'select * from "routes"')
                    && str_contains($s, 'for update'),
            );
            $trip = $this->firstMatching(
                $statements,
                fn (string $s): bool => str_contains($s, 'from "trips"'),
            );

            self::assertNotNull($lock, 'the route was read without a lock');
            self::assertNotNull($trip, 'the trip was never looked up');
            self::assertLessThan($trip, $lock, 'the trip was touched before the lock');

            self::assertNull(
                $this->firstMatching(
                    $statements,
                    fn (string $s): bool => str_starts_with($s, 'select * from "seat_requests"')
                        && str_contains($s, 'for update'),
                ),
                'a seat request was locked, inverting the request → route order',
            );
        }
    }

    // ------------------------------------------- one journey is not another

    /**
     * CARRIES WEIGHT. Another date's ending is not this journey's.
     *
     * A completed trip on one date says nothing about another. Resolving a trip
     * by route alone would hand this command that finished journey and answer
     * `already_completed` for a journey nobody has started — a refusal about
     * the wrong day.
     *
     * Reached with a planted row, because no command in 16a can make a second
     * journey on one route and no guard was weakened to reach it.
     */
    public function test_a_completed_journey_on_another_date_does_not_end_this_one(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $this->plantCompleted($route, $route->soleServiceDate()->subDay());

        self::assertSame(
            RefusalReason::TripNotStarted,
            $this->refusal(fn () => $this->complete($driver, $route))->reason,
        );
        self::assertSame(
            RefusalReason::TripNotStarted,
            $this->refusal(fn () => $this->abort($driver, $route))->reason,
        );
    }

    /** And ending this journey leaves the other date's alone. */
    public function test_ending_this_journey_does_not_touch_another_dates(): void
    {
        [$driver, $route] = $this->started();
        $other = $route->soleServiceDate()->subDay();
        $this->plantCompleted($route, $other);

        $this->complete($driver, $route);

        self::assertSame(2, Trip::query()->where('route_id', $route->id)->count());
        self::assertSame(TripStatus::Completed, Trip::query()
            ->where('route_id', $route->id)
            ->where('service_date', $other->toDateString())
            ->sole()
            ->status);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * A finished journey on a date of this route, written straight to the row.
     *
     * No command in 16a can produce a second date, so the dated lookups are
     * proved with a row rather than by lifting a recurrence guard.
     */
    private function plantCompleted(Route $route, CarbonImmutable $serviceDate): void
    {
        DB::table('trips')->insert([
            'id' => '01991e00-0000-7000-8000-0000000000ff',
            'route_id' => $route->id,
            'service_date' => $serviceDate->toDateString(),
            'status' => TripStatus::Completed->value,
            'started_at' => $this->departed()->subHour(),
            'completed_at' => $this->departed(),
            'aborted_at' => null,
            'created_at' => $this->departed()->subHour(),
            'updated_at' => $this->departed(),
        ]);
    }

    /**
     * A driver whose one-off journey is already under way.
     *
     * @return array{0: Account, 1: Route}
     */
    private function started(
        string $phone = '+905321110000',
        string $routeTail = '01',
        bool $withAcceptedPassenger = false,
    ): array {
        $driver = $this->driver($phone);
        $route = $this->route($driver, $routeTail);

        if ($withAcceptedPassenger) {
            app(RequestSeat::class)($this->passenger(), $this->requestId(), $route->id);
            app(AcceptSeatRequest::class)($driver, $this->requestId());
        }

        app(StartTrip::class)($driver, $route->id, $this->departed());

        return [$driver, $route];
    }

    private function complete(
        Account $driver,
        Route $route,
        ?CarbonImmutable $at = null,
    ): EndedTrip {
        return app(CompleteTrip::class)($driver, $route->id, $at);
    }

    private function abort(
        Account $driver,
        Route $route,
        ?CarbonImmutable $at = null,
    ): EndedTrip {
        return app(AbortTrip::class)($driver, $route->id, $at);
    }

    private function refusal(callable $command): TripRefused
    {
        try {
            $command();
        } catch (TripRefused $refused) {
            return $refused;
        }

        self::fail('the command was not refused');
    }

    /** An instant comfortably after the fixture route's departure. */
    private function departed(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(4);
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

    private function route(Account $driver, string $tail = '01'): Route
    {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            $this->routeId($tail),
            $this->place('kadikoy-iskele'),
            $this->place('levent-metro'),
            RouteDeparture::fromInput(
                Recurrence::Once,
                CarbonImmutable::now()->addDays(3)->format('Y-m-d'),
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
