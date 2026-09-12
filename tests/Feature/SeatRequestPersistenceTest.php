<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use App\SeatRequests\SeatRequestStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * What the database itself refuses.
 *
 * Every write here goes through `DB::table` rather than a domain action, and
 * that is the point: the commands arrive in the next commit, and if they were
 * the only thing keeping these invariants true then a console command, a
 * migration or a hand-written UPDATE could break them without a test noticing.
 * A check constraint cannot be bypassed by any of those.
 *
 * The two invariants worth the most here are the lifetime uniqueness — which a
 * partial index would silently weaken into a re-request policy nobody decided —
 * and the status/timestamp shape, which is what stops a row claiming to be
 * pending while carrying somebody's decision.
 */
final class SeatRequestPersistenceTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private const KADIKOY = 'kadikoy-iskele';

    private const LEVENT = 'levent-metro';

    private const MASLAK = 'maslak-oto-sanayi';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    // ------------------------------------------------------------- the shape

    public function test_a_pending_request_persists_with_neither_end_timestamp(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $this->insert($route, $passenger);

        $request = SeatRequest::query()->sole();

        self::assertSame(SeatRequestStatus::Pending, $request->status);
        self::assertTrue($request->isPending());
        self::assertNull($request->decided_at);
        self::assertNull($request->withdrawn_at);
        self::assertInstanceOf(CarbonImmutable::class, $request->requested_at);
        self::assertSame($route->id, $request->route_id);
        self::assertSame($passenger->id, $request->account_id);
    }

    /** Every state the enum names must be a state the column accepts. */
    public function test_the_database_accepts_all_four_statuses(): void
    {
        $route = $this->route($this->driver());

        foreach (SeatRequestStatus::cases() as $index => $status) {
            [$decidedAt, $withdrawnAt] = $this->timestampsFor($status);

            $this->insert(
                $route,
                // A fresh passenger each time: lifetime uniqueness allows one
                // request per member per route, and this is about the status
                // column, not about that rule.
                $this->passenger('+90532100000'.$index),
                $status->value,
                decidedAt: $decidedAt,
                withdrawnAt: $withdrawnAt,
                id: $this->id('1'.$index),
            );
        }

        self::assertSame(4, SeatRequest::query()->count());
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->route($this->driver()), $this->passenger(), 'expired');
    }

    // -------------------------------------------- status / timestamp shape

    public function test_a_pending_request_cannot_carry_a_decision_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route($this->driver()),
            $this->passenger(),
            'pending',
            decidedAt: CarbonImmutable::now(),
        );
    }

    public function test_a_pending_request_cannot_carry_a_withdrawal_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route($this->driver()),
            $this->passenger(),
            'pending',
            withdrawnAt: CarbonImmutable::now(),
        );
    }

    public function test_an_accepted_request_requires_a_decision_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->route($this->driver()), $this->passenger(), 'accepted');
    }

    public function test_an_accepted_request_cannot_also_be_withdrawn(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route($this->driver()),
            $this->passenger(),
            'accepted',
            decidedAt: CarbonImmutable::now(),
            withdrawnAt: CarbonImmutable::now(),
        );
    }

    public function test_a_declined_request_requires_a_decision_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->route($this->driver()), $this->passenger(), 'declined');
    }

    public function test_a_declined_request_cannot_also_be_withdrawn(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route($this->driver()),
            $this->passenger(),
            'declined',
            decidedAt: CarbonImmutable::now(),
            withdrawnAt: CarbonImmutable::now(),
        );
    }

    public function test_a_withdrawn_request_requires_a_withdrawal_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->route($this->driver()), $this->passenger(), 'withdrawn');
    }

    public function test_a_withdrawn_request_cannot_carry_a_decision_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(
            $this->route($this->driver()),
            $this->passenger(),
            'withdrawn',
            decidedAt: CarbonImmutable::now(),
            withdrawnAt: CarbonImmutable::now(),
        );
    }

    // ------------------------------------------------ lifetime uniqueness

    public function test_a_member_cannot_ask_twice_for_the_same_route(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $this->insert($route, $passenger);

        $this->expectException(QueryException::class);

        $this->insert($route, $passenger, id: $this->id('99'));
    }

    /**
     * THE MUTATION THAT MATTERS.
     *
     * A partial unique index over the live states would pass every other test
     * in this file and fail these two. That difference is a product policy —
     * whether a declined member may ask again — and it is not being decided by
     * an index shape.
     */
    public function test_a_declined_request_does_not_free_the_slot(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $this->insert($route, $passenger, 'declined', decidedAt: CarbonImmutable::now());

        $this->expectException(QueryException::class);

        $this->insert($route, $passenger, id: $this->id('99'));
    }

    public function test_a_withdrawn_request_does_not_free_the_slot(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $this->insert($route, $passenger, 'withdrawn', withdrawnAt: CarbonImmutable::now());

        $this->expectException(QueryException::class);

        $this->insert($route, $passenger, id: $this->id('99'));
    }

    public function test_two_members_may_ask_for_the_same_route(): void
    {
        $route = $this->route($this->driver());

        $this->insert($route, $this->passenger('+905321000001'), id: $this->id('01'));
        $this->insert($route, $this->passenger('+905321000002'), id: $this->id('02'));

        self::assertSame(2, SeatRequest::query()->count());
    }

    public function test_one_member_may_ask_for_two_routes(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();

        $this->insert($this->route($driver), $passenger, id: $this->id('01'));
        $this->insert(
            $this->route($driver, routeId: $this->routeId('02'), destination: self::MASLAK),
            $passenger,
            id: $this->id('02'),
        );

        self::assertSame(2, SeatRequest::query()->count());
    }

    // --------------------------------------------------- foreign keys

    public function test_a_request_cannot_name_a_route_that_does_not_exist(): void
    {
        $passenger = $this->passenger();

        $this->expectException(QueryException::class);

        DB::table('seat_requests')->insert([
            'id' => $this->id('01'),
            'route_id' => $this->routeId('ff'),
            'service_date' => CarbonImmutable::now()->toDateString(),
            'account_id' => $passenger->id,
            'status' => 'pending',
            'requested_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /** A request is a request FOR a journey, so it does not outlive one. */
    public function test_deleting_the_route_deletes_its_requests(): void
    {
        $route = $this->route($this->driver());
        $this->insert($route, $this->passenger());

        Route::query()->whereKey($route->id)->delete();

        self::assertSame(0, SeatRequest::query()->count());
    }

    public function test_deleting_the_passenger_deletes_their_requests(): void
    {
        $passenger = $this->passenger();
        $this->insert($this->route($this->driver()), $passenger);

        Account::query()->whereKey($passenger->id)->delete();

        self::assertSame(0, SeatRequest::query()->count());
    }

    // ------------------------------------------------------- the model

    public function test_the_model_reaches_the_route_and_the_passenger(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();
        $this->insert($route, $passenger);

        $request = SeatRequest::query()->with(['route', 'passenger'])->sole();

        self::assertSame($route->id, $request->route->id);
        self::assertSame($passenger->id, $request->passenger->id);
    }

    /**
     * The driver is the route's owner and is stored nowhere else.
     *
     * A `driver_account_id` column would be a second answer to "whose journey
     * is this", and every authorization check would have to pick one.
     */
    public function test_the_request_stores_no_driver_of_its_own(): void
    {
        self::assertSame(
            [
                'id', 'route_id', 'account_id', 'status', 'requested_at',
                'decided_at', 'withdrawn_at', 'created_at', 'updated_at',
                'service_date',
            ],
            Schema::getColumnListing('seat_requests'),
        );
    }

    /** RideMate charges nobody, so no column here may suggest otherwise. */
    public function test_the_table_carries_no_money_or_seat_count_column(): void
    {
        $forbidden = [
            'cost', 'fare', 'price', 'amount', 'payment', 'payout',
            'seats', 'seat_count', 'quantity', 'message', 'note',
        ];

        foreach (Schema::getColumnListing('seat_requests') as $column) {
            foreach ($forbidden as $word) {
                self::assertStringNotContainsString(
                    $word,
                    $column,
                    "seat_requests.$column names something Phase 13 v1 does not have",
                );
            }
        }
    }

    public function test_only_pending_is_non_terminal(): void
    {
        self::assertFalse(SeatRequestStatus::Pending->isTerminal());
        self::assertTrue(SeatRequestStatus::Accepted->isTerminal());
        self::assertTrue(SeatRequestStatus::Declined->isTerminal());
        self::assertTrue(SeatRequestStatus::Withdrawn->isTerminal());
    }

    // ------------------------------------------------- schema introspection

    /**
     * The uniqueness is total, dated, and the constraint definition says so.
     *
     * A `WHERE` clause here would be the partial index this phase refused. The
     * service date joined it in Phase 16a: a member may ask about Monday and
     * Tuesday on one plan, and those are two journeys rather than one asking
     * repeated.
     */
    public function test_the_uniqueness_is_not_partial(): void
    {
        $definition = DB::scalar(
            "select indexdef from pg_indexes
             where tablename = 'seat_requests'
               and indexname = 'seat_requests_one_per_journey_per_member'"
        );

        self::assertIsString($definition);
        self::assertStringContainsString('UNIQUE', $definition);
        self::assertStringContainsString('route_id', $definition);
        self::assertStringContainsString('service_date', $definition);
        self::assertStringContainsString('account_id', $definition);
        self::assertStringNotContainsString('WHERE', $definition);
    }

    /** The route-scoped rule it replaced is gone, not merely shadowed. */
    public function test_the_route_scoped_uniqueness_is_gone(): void
    {
        self::assertNull(DB::scalar(
            "select indexdef from pg_indexes
             where tablename = 'seat_requests'
               and indexname = 'seat_requests_one_per_route_per_member'"
        ));
    }

    public function test_both_keyset_indexes_exist(): void
    {
        /** @var list<string> $indexes */
        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'seat_requests')
            ->orderBy('indexname')
            ->pluck('indexname')
            ->all();

        self::assertContains('seat_requests_route_id_created_at_id_index', $indexes);
        self::assertContains('seat_requests_account_id_created_at_id_index', $indexes);
    }

    /** No `expired`, for the reason SeatRequestStatus gives. */
    public function test_the_status_constraint_names_exactly_four_states(): void
    {
        $definition = DB::scalar(
            "select pg_get_constraintdef(oid) from pg_constraint
             where conname = 'seat_requests_status_check'"
        );

        self::assertIsString($definition);
        foreach (['pending', 'accepted', 'declined', 'withdrawn'] as $state) {
            self::assertStringContainsString($state, $definition);
        }
        self::assertStringNotContainsString('expired', $definition);
        self::assertStringNotContainsString('cancelled', $definition);
    }

    // ------------------------------------------------------------- fixtures

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
        ?string $routeId = null,
        string $destination = self::LEVENT,
    ): Route {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            $routeId ?? $this->routeId('01'),
            $this->place(self::KADIKOY),
            $this->place($destination),
            RouteDeparture::fromInput(
                Recurrence::Once,
                CarbonImmutable::now()->addDays(3)->format('Y-m-d'),
                '08:00',
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

    private function insert(
        Route $route,
        Account $passenger,
        string $status = 'pending',
        ?CarbonImmutable $decidedAt = null,
        ?CarbonImmutable $withdrawnAt = null,
        ?string $id = null,
    ): void {
        DB::table('seat_requests')->insert([
            'id' => $id ?? $this->id('01'),
            'route_id' => $route->id,
            'service_date' => $route->departure_date,
            'account_id' => $passenger->id,
            'status' => $status,
            'requested_at' => CarbonImmutable::now(),
            'decided_at' => $decidedAt,
            'withdrawn_at' => $withdrawnAt,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * The timestamps a given status must carry to satisfy the shape checks.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function timestampsFor(SeatRequestStatus $status): array
    {
        $now = CarbonImmutable::now();

        return match ($status) {
            SeatRequestStatus::Pending => [null, null],
            SeatRequestStatus::Accepted, SeatRequestStatus::Declined => [$now, null],
            SeatRequestStatus::Withdrawn => [null, $now],
        };
    }

    private function id(string $tail): string
    {
        return '01991d00-0000-7000-8000-0000000000'.$tail;
    }

    private function routeId(string $tail): string
    {
        return '01991c00-0000-7000-8000-0000000000'.$tail;
    }
}
