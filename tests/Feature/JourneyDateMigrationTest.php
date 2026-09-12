<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * The service-date migration, driven rather than described.
 *
 * WHY THESE TESTS RUN THE REAL FILE
 *
 * `RefreshDatabase` migrates an empty database, so the backfill in `up()`
 * touches no rows during an ordinary suite run and is therefore never exercised
 * by it. The tempting substitute — copying the migration's own `UPDATE` into a
 * test and asserting it works — proves only that the copy works, which is the
 * one thing nobody needed to know.
 *
 * So each test here takes the migration file itself, runs its real `down()` to
 * put the schema back the way it was before Phase 16a, writes rows in the shape
 * the pilot's database actually holds, and then runs its real `up()`. What is
 * asserted afterwards is what happened to those rows.
 *
 * WHY THIS IS SAFE
 *
 * `Tests\TestCase` refuses to run against anything but `ridemate_test`;
 * PostgreSQL's DDL is transactional; and `RefreshDatabase` already wraps every
 * test in a transaction it rolls back. The schema changes below are undone with
 * everything else when the test ends. No development database is touched, and
 * nothing here resets, wipes or freshens a schema.
 */
final class JourneyDateMigrationTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    // ------------------------------------------------------- the forward path

    /**
     * CARRIES WEIGHT. Legacy rows come out of `up()` carrying their own date.
     *
     * This is the claim the phase rests on: every row that existed before
     * Phase 16a belongs to a one-off route, so the date it ran on is already
     * knowable and none of it has to be guessed.
     */
    public function test_existing_rows_are_backfilled_from_their_route(): void
    {
        $migration = $this->migration();
        $route = $this->route();
        $passenger = $this->account('passenger');

        $this->invokeMigration($migration, 'down');

        $this->legacySeatRequest($route, $passenger);
        $this->legacyTrip($route);

        $this->invokeMigration($migration, 'up');

        self::assertSame(
            $route->departure_date?->toDateString(),
            DB::scalar('select service_date from seat_requests where id = ?', [$this->requestId()]),
        );
        self::assertSame(
            $route->departure_date?->toDateString(),
            DB::scalar('select service_date from trips where id = ?', [$this->tripId()]),
        );
    }

    /** Nothing is left nullable for a later write to forget. */
    public function test_the_column_is_required_on_both_tables(): void
    {
        foreach (['seat_requests', 'trips'] as $table) {
            self::assertSame('NO', DB::scalar(
                'select is_nullable from information_schema.columns
                  where table_name = ? and column_name = ?',
                [$table, 'service_date'],
            ), $table);
        }
    }

    /** A date, not an instant: it carries no time of day and no zone. */
    public function test_the_column_is_a_date_on_both_tables(): void
    {
        foreach (['seat_requests', 'trips'] as $table) {
            self::assertSame('date', DB::scalar(
                'select data_type from information_schema.columns
                  where table_name = ? and column_name = ?',
                [$table, 'service_date'],
            ), $table);
        }
    }

    // ------------------------------------------------------- the new shape

    /** One trip per dated journey, which is what `unique (route_id)` said. */
    public function test_the_trip_uniqueness_is_dated_and_not_partial(): void
    {
        $definition = DB::scalar(
            "select indexdef from pg_indexes
              where tablename = 'trips' and indexname = 'trips_one_per_journey'"
        );

        self::assertIsString($definition);
        self::assertStringContainsString('UNIQUE', $definition);
        self::assertStringContainsString('route_id', $definition);
        self::assertStringContainsString('service_date', $definition);
        self::assertStringNotContainsString('WHERE', $definition);
    }

    public function test_the_route_scoped_trip_uniqueness_is_gone(): void
    {
        self::assertNull(DB::scalar(
            "select indexdef from pg_indexes
              where tablename = 'trips' and indexname = 'trips_route_id_unique'"
        ));
    }

    /**
     * The foreign key lost its only index, so one was put back.
     *
     * PostgreSQL does not index a foreign key column on its own, and every trip
     * lookup is route-scoped.
     */
    public function test_the_trip_route_column_keeps_a_non_unique_index(): void
    {
        $definition = DB::scalar(
            "select indexdef from pg_indexes
              where tablename = 'trips' and indexname = 'trips_route_id_index'"
        );

        self::assertIsString($definition);
        self::assertStringNotContainsString('UNIQUE', $definition);
    }

    /** The foreign key itself was never what the unique index was holding up. */
    public function test_the_trip_foreign_key_survived(): void
    {
        self::assertSame(1, (int) DB::scalar(
            "select count(*) from information_schema.table_constraints
              where table_name = 'trips'
                and constraint_type = 'FOREIGN KEY'
                and constraint_name = 'trips_route_id_foreign'"
        ));
    }

    // --------------------------------------- what the new shape now permits

    /**
     * CARRIES WEIGHT. Two dates on one route are now representable.
     *
     * Nothing can produce them yet — both recurrence guards are still in place
     * — but the schema is what 16b will rely on, so it is asserted here rather
     * than assumed there.
     */
    public function test_one_route_may_hold_a_trip_on_two_dates(): void
    {
        $route = $this->route();

        $this->trip($route, '01', $route->soleServiceDate()->toDateString());
        $this->trip($route, '02', $route->soleServiceDate()->addDay()->toDateString());

        self::assertSame(2, (int) DB::scalar(
            'select count(*) from trips where route_id = ?',
            [$route->id],
        ));
    }

    public function test_one_member_may_ask_about_two_dates_of_one_route(): void
    {
        $route = $this->route();
        $passenger = $this->account('passenger');

        $this->seatRequest($route, $passenger, '01', $route->soleServiceDate()->toDateString());
        $this->seatRequest($route, $passenger, '02', $route->soleServiceDate()->addDay()->toDateString());

        self::assertSame(2, (int) DB::scalar(
            'select count(*) from seat_requests where route_id = ? and account_id = ?',
            [$route->id, $passenger->id],
        ));
    }

    /** And the same date twice is still one asking, not two. */
    public function test_one_member_still_cannot_ask_twice_about_one_date(): void
    {
        $route = $this->route();
        $passenger = $this->account('passenger');
        $date = $route->soleServiceDate()->toDateString();

        $this->seatRequest($route, $passenger, '01', $date);

        $this->expectException(QueryException::class);

        $this->seatRequest($route, $passenger, '02', $date);
    }

    /** And one dated journey is still made at most once. */
    public function test_one_route_still_cannot_hold_two_trips_on_one_date(): void
    {
        $route = $this->route();
        $date = $route->soleServiceDate()->toDateString();

        $this->trip($route, '01', $date);

        $this->expectException(QueryException::class);

        $this->trip($route, '02', $date);
    }

    // ------------------------------------------------------- rolling back

    /** Reversible while the data still fits the shape it is going back to. */
    public function test_rollback_succeeds_on_legacy_compatible_data(): void
    {
        $migration = $this->migration();
        $route = $this->route();
        $passenger = $this->account('passenger');

        $this->seatRequest($route, $passenger, '01', $route->soleServiceDate()->toDateString());
        $this->trip($route, '01', $route->soleServiceDate()->toDateString());

        $this->invokeMigration($migration, 'down');

        self::assertFalse(Schema::hasColumn('seat_requests', 'service_date'));
        self::assertFalse(Schema::hasColumn('trips', 'service_date'));
        self::assertIsString(DB::scalar(
            "select indexdef from pg_indexes
              where tablename = 'seat_requests'
                and indexname = 'seat_requests_one_per_route_per_member'"
        ));

        // Put it back, so the assertion above is the only thing this test
        // changed about the schema.
        $this->invokeMigration($migration, 'up');
    }

    /**
     * CARRIES WEIGHT. Rollback refuses rather than deleting somebody's journey.
     *
     * Once two dates exist for one member on one route, restoring
     * `unique (route_id, account_id)` is not a schema change — it is a demand
     * that one of those rows be destroyed. The migration stops instead, and
     * changes nothing on its way out.
     */
    public function test_rollback_refuses_when_a_member_holds_two_dates(): void
    {
        $migration = $this->migration();
        $route = $this->route();
        $passenger = $this->account('passenger');

        $this->seatRequest($route, $passenger, '01', $route->soleServiceDate()->toDateString());
        $this->seatRequest($route, $passenger, '02', $route->soleServiceDate()->addDay()->toDateString());

        try {
            $this->invokeMigration($migration, 'down');
            self::fail('the rollback should have refused');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('Refusing to roll back', $refusal->getMessage());
        }

        // Nothing was changed on the way out, and no row was destroyed.
        self::assertTrue(Schema::hasColumn('seat_requests', 'service_date'));
        self::assertSame(2, (int) DB::scalar(
            'select count(*) from seat_requests where route_id = ?',
            [$route->id],
        ));
    }

    /** The same refusal for the trip side, which has its own old rule. */
    public function test_rollback_refuses_when_a_route_holds_two_trips(): void
    {
        $migration = $this->migration();
        $route = $this->route();

        $this->trip($route, '01', $route->soleServiceDate()->toDateString());
        $this->trip($route, '02', $route->soleServiceDate()->addDay()->toDateString());

        try {
            $this->invokeMigration($migration, 'down');
            self::fail('the rollback should have refused');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('Refusing to roll back', $refusal->getMessage());
        }

        self::assertTrue(Schema::hasColumn('trips', 'service_date'));
        self::assertSame(2, (int) DB::scalar(
            'select count(*) from trips where route_id = ?',
            [$route->id],
        ));
    }

    // ------------------------------------------------------------- fixtures

    /**
     * Runs one of the migration file's own methods.
     *
     * Reflection rather than `$migration->up()` because `Migration` declares
     * neither method: they are a convention the migrator invokes dynamically,
     * so a direct call is something static analysis cannot check. This keeps
     * the call honest and the analyser at level 8 with no suppression — and it
     * is still the real file being run, which is the whole point of this class.
     */
    private function invokeMigration(Migration $migration, string $method): void
    {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }

    private function migration(): Migration
    {
        $migration = require database_path(
            'migrations/2026_09_13_100000_add_service_date_to_journeys.php',
        );

        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /** A seat request in the shape the table held before Phase 16a. */
    private function legacySeatRequest(Route $route, Account $passenger): void
    {
        DB::table('seat_requests')->insert([
            'id' => $this->requestId(),
            'route_id' => $route->id,
            'account_id' => $passenger->id,
            'status' => 'accepted',
            'requested_at' => CarbonImmutable::now(),
            'decided_at' => CarbonImmutable::now(),
            'withdrawn_at' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /** A trip in the shape the table held before Phase 16a. */
    private function legacyTrip(Route $route): void
    {
        DB::table('trips')->insert([
            'id' => $this->tripId(),
            'route_id' => $route->id,
            'status' => 'completed',
            'started_at' => CarbonImmutable::now()->subHour(),
            'completed_at' => CarbonImmutable::now(),
            'aborted_at' => null,
            'created_at' => CarbonImmutable::now()->subHour(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function seatRequest(Route $route, Account $passenger, string $tail, string $date): void
    {
        DB::table('seat_requests')->insert([
            'id' => $this->requestId($tail),
            'route_id' => $route->id,
            'account_id' => $passenger->id,
            'service_date' => $date,
            'status' => 'pending',
            'requested_at' => CarbonImmutable::now(),
            'decided_at' => null,
            'withdrawn_at' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function trip(Route $route, string $tail, string $date): void
    {
        DB::table('trips')->insert([
            'id' => $this->tripId($tail),
            'route_id' => $route->id,
            'service_date' => $date,
            'status' => 'in_progress',
            'started_at' => CarbonImmutable::now(),
            'completed_at' => null,
            'aborted_at' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function route(): Route
    {
        $driver = $this->account('driver');
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            '01991c00-0000-7000-8000-000000000001',
            Place::query()->where('slug', 'kadikoy-iskele')->sole(),
            Place::query()->where('slug', 'levent-metro')->sole(),
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

    private function account(string $seed): Account
    {
        $account = $this->createAccount('+9053211100'.substr(md5($seed), 0, 2));
        (new SaveProfile)($account, DisplayName::fromInput('İrem Yılmaz'));

        return $account;
    }

    private function requestId(string $tail = '01'): string
    {
        return '01991d00-0000-7000-8000-0000000000'.str_pad($tail, 2, '0', STR_PAD_LEFT);
    }

    private function tripId(string $tail = '01'): string
    {
        return '01991e00-0000-7000-8000-0000000000'.str_pad($tail, 2, '0', STR_PAD_LEFT);
    }
}
