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
use App\Trips\TripLifecycle;
use App\Trips\TripState;
use App\Trips\TripStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * What the database refuses about a journey being made.
 *
 * Every write goes through `DB::table` rather than a domain action, and that is
 * the point: the commands arrive in B2 and B3, and if they were the only thing
 * keeping these invariants true then a console command or a hand-written UPDATE
 * could break them without a test noticing.
 *
 * The invariant worth the most here is that a row cannot claim two endings, or
 * an ending it does not carry the time of.
 */
final class TripPersistenceTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    // ------------------------------------------------------------- the shape

    public function test_a_started_trip_persists_with_neither_ending(): void
    {
        $route = $this->route();

        $this->insert($route);

        $trip = Trip::query()->sole();

        self::assertSame(TripStatus::InProgress, $trip->status);
        self::assertTrue($trip->isInProgress());
        self::assertInstanceOf(CarbonImmutable::class, $trip->started_at);
        self::assertNull($trip->completed_at);
        self::assertNull($trip->aborted_at);
        self::assertSame($route->id, $trip->route_id);
    }

    /** Every state the enum names must be a state the column accepts. */
    public function test_the_database_accepts_all_three_stored_statuses(): void
    {
        foreach (TripStatus::cases() as $index => $status) {
            [$completedAt, $abortedAt] = $this->endingFor($status);

            $this->insert(
                // A fresh route each time: one trip per route, and this is
                // about the status column rather than about that rule.
                $this->route($this->routeId((string) $index)),
                $status->value,
                completedAt: $completedAt,
                abortedAt: $abortedAt,
                id: $this->id((string) $index),
            );
        }

        self::assertSame(3, Trip::query()->count());
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->route(), 'not_started');
    }

    /** `not_started` in particular: it is the absence of a row, never a row. */
    public function test_the_status_constraint_names_exactly_three_states(): void
    {
        $definition = DB::scalar(
            "select pg_get_constraintdef(oid) from pg_constraint
             where conname = 'trips_status_check'"
        );

        self::assertIsString($definition);
        foreach (['in_progress', 'completed', 'aborted'] as $state) {
            self::assertStringContainsString($state, $definition);
        }
        self::assertStringNotContainsString('not_started', $definition);
        self::assertStringNotContainsString('expired', $definition);
        self::assertStringNotContainsString('cancelled', $definition);
    }

    // ------------------------------------------------- status / ending shape

    public function test_a_trip_in_progress_cannot_carry_a_completion_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route(),
            'in_progress',
            completedAt: CarbonImmutable::now(),
        );
    }

    public function test_a_trip_in_progress_cannot_carry_an_abortion_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route(),
            'in_progress',
            abortedAt: CarbonImmutable::now(),
        );
    }

    public function test_a_completed_trip_requires_its_completion_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->route(), 'completed');
    }

    public function test_an_aborted_trip_requires_its_abortion_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->route(), 'aborted');
    }

    /**
     * A journey ends once.
     *
     * Enforced by the two shape checks rather than by an exclusivity
     * constraint: whichever status a row claims, one check demands its
     * timestamp while the other forbids the second. A separate
     * `completed_at is null or aborted_at is null` was written and removed
     * because no row can reach it — deleting it failed nothing.
     */
    public function test_a_trip_cannot_carry_two_endings(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route(),
            'completed',
            completedAt: CarbonImmutable::now(),
            abortedAt: CarbonImmutable::now(),
        );
    }

    public function test_a_completed_trip_cannot_also_be_aborted(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route(),
            'aborted',
            completedAt: CarbonImmutable::now(),
            abortedAt: CarbonImmutable::now(),
        );
    }

    // ------------------------------------------------------ one per journey

    public function test_a_route_cannot_be_made_twice(): void
    {
        $route = $this->route();

        $this->insert($route);

        $this->expectException(QueryException::class);

        $this->insert($route, id: $this->id('99'));
    }

    public function test_a_trip_cannot_name_a_route_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        DB::table('trips')->insert([
            'id' => $this->id('01'),
            'route_id' => $this->routeId('ff'),
            'service_date' => CarbonImmutable::now()->toDateString(),
            'status' => 'in_progress',
            'started_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /** A trip is the making of a journey, so it does not outlive one. */
    public function test_deleting_the_route_deletes_its_trip(): void
    {
        $route = $this->route();
        $this->insert($route);

        Route::query()->whereKey($route->id)->delete();

        self::assertSame(0, Trip::query()->count());
    }

    public function test_a_route_reaches_its_trip_and_has_at_most_one(): void
    {
        $route = $this->route();
        $this->insert($route);

        $found = Route::query()->with('trip')->findOrFail($route->id);

        $trip = $found->trip;

        self::assertInstanceOf(Trip::class, $trip);
        self::assertSame($route->id, $trip->route_id);
    }

    public function test_a_route_that_was_never_started_has_no_trip(): void
    {
        $route = $this->route();

        self::assertNull(Route::query()->findOrFail($route->id)->trip);
    }

    // ------------------------------------------------ the lifecycle projection

    /**
     * THE POINT OF THE PROJECTION.
     *
     * No row is not missing data. It is `not_started`, which nothing stores
     * because storing it would need something to keep it true for every route
     * ever published.
     */
    public function test_no_trip_projects_as_not_started_with_no_timestamps(): void
    {
        $lifecycle = TripLifecycle::of(null);

        self::assertSame(TripState::NotStarted, $lifecycle->state);
        self::assertNull($lifecycle->startedAt);
        self::assertNull($lifecycle->completedAt);
        self::assertNull($lifecycle->abortedAt);
        self::assertFalse($lifecycle->hasStarted());
        self::assertFalse($lifecycle->isInProgress());
    }

    public function test_each_stored_status_projects_as_its_own_state(): void
    {
        $expected = [
            TripStatus::InProgress->value => TripState::InProgress,
            TripStatus::Completed->value => TripState::Completed,
            TripStatus::Aborted->value => TripState::Aborted,
        ];

        foreach (TripStatus::cases() as $index => $status) {
            [$completedAt, $abortedAt] = $this->endingFor($status);
            $route = $this->route($this->routeId((string) $index));

            $this->insert(
                $route,
                $status->value,
                completedAt: $completedAt,
                abortedAt: $abortedAt,
                id: $this->id((string) $index),
            );

            $lifecycle = TripLifecycle::of(
                Trip::query()->where('route_id', $route->id)->sole(),
            );

            self::assertSame($expected[$status->value], $lifecycle->state);
            self::assertTrue($lifecycle->hasStarted());
            self::assertNotNull($lifecycle->startedAt);
        }
    }

    /** The persisted times, never anything derived from the clock. */
    public function test_the_projection_carries_the_stored_timestamps(): void
    {
        $route = $this->route();
        $started = CarbonImmutable::parse('2026-09-14T08:25:00+00:00');
        $completed = CarbonImmutable::parse('2026-09-14T09:10:00+00:00');

        $this->insert(
            $route,
            'completed',
            startedAt: $started,
            completedAt: $completed,
        );

        $lifecycle = TripLifecycle::of(Trip::query()->sole());

        self::assertNotNull($lifecycle->startedAt);
        self::assertNotNull($lifecycle->completedAt);
        self::assertTrue($started->equalTo($lifecycle->startedAt));
        self::assertTrue($completed->equalTo($lifecycle->completedAt));
        self::assertNull($lifecycle->abortedAt);
        self::assertFalse($lifecycle->isInProgress());
    }

    public function test_only_in_progress_is_non_terminal(): void
    {
        self::assertFalse(TripStatus::InProgress->isTerminal());
        self::assertTrue(TripStatus::Completed->isTerminal());
        self::assertTrue(TripStatus::Aborted->isTerminal());
    }

    // ------------------------------------------------------- what is absent

    /**
     * A trip knows when it happened, and nothing else about it.
     *
     * No coordinates, no distance, no duration, no abort reason, no cost, and
     * nobody aboard: participation stays with accepted seat requests, and a
     * second copy could only ever disagree with the first.
     */
    public function test_the_table_carries_only_lifecycle_columns(): void
    {
        self::assertSame(
            [
                'id', 'route_id', 'status', 'started_at', 'completed_at',
                'aborted_at', 'created_at', 'updated_at', 'service_date',
            ],
            Schema::getColumnListing('trips'),
        );
    }

    public function test_the_table_names_nothing_it_cannot_know(): void
    {
        $forbidden = [
            'lat', 'lon', 'location', 'geo', 'distance', 'duration',
            'reason', 'passenger', 'participant', 'seat', 'cost', 'fare',
            'price', 'earnings', 'rating', 'review',
        ];

        foreach (Schema::getColumnListing('trips') as $column) {
            foreach ($forbidden as $word) {
                self::assertStringNotContainsString(
                    $word,
                    $column,
                    "trips.$column names something Phase 14 does not have",
                );
            }
        }
    }

    // ------------------------------------------------------------- fixtures

    private function route(?string $routeId = null): Route
    {
        $driver = $this->driver($routeId ?? 'x');
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            $routeId ?? $this->routeId('01'),
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

    /** A distinct driver per route, so one member is not publishing twice. */
    private function driver(string $seed): Account
    {
        $account = $this->createAccount(
            '+9053211100'.substr(md5($seed), 0, 2),
        );
        (new SaveProfile)($account, DisplayName::fromInput('İrem Yılmaz'));

        return $account;
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    private function insert(
        Route $route,
        string $status = 'in_progress',
        ?CarbonImmutable $startedAt = null,
        ?CarbonImmutable $completedAt = null,
        ?CarbonImmutable $abortedAt = null,
        ?string $id = null,
    ): void {
        DB::table('trips')->insert([
            'id' => $id ?? $this->id('01'),
            'route_id' => $route->id,
            'service_date' => $route->departure_date,
            'status' => $status,
            'started_at' => $startedAt ?? CarbonImmutable::now(),
            'completed_at' => $completedAt,
            'aborted_at' => $abortedAt,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * The ending timestamps a given status must carry.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function endingFor(TripStatus $status): array
    {
        $now = CarbonImmutable::now();

        return match ($status) {
            TripStatus::InProgress => [null, null],
            TripStatus::Completed => [$now, null],
            TripStatus::Aborted => [null, $now],
        };
    }

    private function id(string $tail): string
    {
        return '01991e00-0000-7000-8000-0000000000'.str_pad($tail, 2, '0', STR_PAD_LEFT);
    }

    private function routeId(string $tail): string
    {
        return '01991c00-0000-7000-8000-0000000000'.str_pad($tail, 2, '0', STR_PAD_LEFT);
    }
}
