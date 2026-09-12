<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\CancelRoute;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use App\SeatRequests\AcceptSeatRequest;
use App\SeatRequests\DeclineSeatRequest;
use App\SeatRequests\RefusalReason;
use App\SeatRequests\RequestSeat;
use App\SeatRequests\SeatRequestRefused;
use App\SeatRequests\SeatRequestStatus;
use App\SeatRequests\TransitionedSeatRequest;
use App\SeatRequests\WithdrawSeatRequest;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * Answering a request, and the two orderings that keep the answers honest.
 *
 * The first is target state before route lifecycle: a command that already
 * succeeded must keep succeeding, however the journey has changed since. The
 * second is request lock before route lock, which is what makes the last seat
 * a real quantity rather than two readers agreeing there is room.
 */
final class SeatRequestDecisionTest extends TestCase
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

    // ------------------------------------------------------------ withdraw

    public function test_a_passenger_withdraws_their_pending_request(): void
    {
        [$route, $passenger] = $this->asked();

        $result = $this->withdraw($passenger, $this->id('01'));

        self::assertFalse($result->wasAlreadyInTargetState);
        self::assertSame(SeatRequestStatus::Withdrawn, $result->request->status);
        self::assertNotNull($result->request->withdrawn_at);
        self::assertNull($result->request->decided_at);
    }

    public function test_withdrawing_twice_writes_nothing_the_second_time(): void
    {
        [$route, $passenger] = $this->asked();

        $first = $this->withdraw($passenger, $this->id('01'));
        $before = $this->row($this->id('01'));

        $second = $this->withdraw($passenger, $this->id('01'));
        $after = $this->row($this->id('01'));

        self::assertFalse($first->wasAlreadyInTargetState);
        self::assertTrue($second->wasAlreadyInTargetState);
        self::assertEquals($before->updated_at, $after->updated_at);
        self::assertEquals($before->withdrawn_at, $after->withdrawn_at);
    }

    public function test_an_accepted_request_cannot_be_withdrawn(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->accept($driver, $this->id('01'));

        $refused = $this->refusal(fn () => $this->withdraw($passenger, $this->id('01')));

        self::assertSame(RefusalReason::AlreadyAccepted, $refused->reason);
        self::assertSame(SeatRequestStatus::Accepted, $this->row($this->id('01'))->status);
    }

    public function test_a_declined_request_cannot_be_withdrawn(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->decline($driver, $this->id('01'));

        $refused = $this->refusal(fn () => $this->withdraw($passenger, $this->id('01')));

        self::assertSame(RefusalReason::AlreadyDecided, $refused->reason);
    }

    /** Closing your own pending request needs nobody else's journey to be alive. */
    public function test_a_pending_request_may_be_withdrawn_after_the_journey_was_cancelled(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        app(CancelRoute::class)($driver, $route->id);

        $result = $this->withdraw($passenger, $this->id('01'));

        self::assertSame(SeatRequestStatus::Withdrawn, $result->request->status);
    }

    public function test_a_pending_request_may_be_withdrawn_after_the_journey_departed(): void
    {
        [$route, $passenger] = $this->asked();

        $result = $this->withdraw(
            $passenger,
            $this->id('01'),
            now: CarbonImmutable::now()->addDays(30),
        );

        self::assertSame(SeatRequestStatus::Withdrawn, $result->request->status);
    }

    public function test_another_passenger_cannot_withdraw_it(): void
    {
        $this->asked();
        $stranger = $this->passenger('+905322229999');

        $this->expectException(ModelNotFoundException::class);

        $this->withdraw($stranger, $this->id('01'));
    }

    // ------------------------------------------------------------- decline

    public function test_a_driver_declines_a_pending_request(): void
    {
        [$route, $passenger, $driver] = $this->asked();

        $result = $this->decline($driver, $this->id('01'));

        self::assertFalse($result->wasAlreadyInTargetState);
        self::assertSame(SeatRequestStatus::Declined, $result->request->status);
        self::assertNotNull($result->request->decided_at);
        self::assertNull($result->request->withdrawn_at);
    }

    public function test_declining_twice_writes_nothing_the_second_time(): void
    {
        [$route, $passenger, $driver] = $this->asked();

        $this->decline($driver, $this->id('01'));
        $before = $this->row($this->id('01'));

        $second = $this->decline($driver, $this->id('01'));
        $after = $this->row($this->id('01'));

        self::assertTrue($second->wasAlreadyInTargetState);
        self::assertEquals($before->updated_at, $after->updated_at);
        self::assertEquals($before->decided_at, $after->decided_at);
    }

    public function test_an_accepted_request_cannot_be_declined(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->accept($driver, $this->id('01'));

        $refused = $this->refusal(fn () => $this->decline($driver, $this->id('01')));

        self::assertSame(RefusalReason::AlreadyAccepted, $refused->reason);
    }

    public function test_a_withdrawn_request_cannot_be_declined(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->withdraw($passenger, $this->id('01'));

        $refused = $this->refusal(fn () => $this->decline($driver, $this->id('01')));

        self::assertSame(RefusalReason::Withdrawn, $refused->reason);
    }

    /**
     * THE LOCKED PRODUCT DECISION.
     *
     * Declining creates no obligation and frees no seat, and forbidding it here
     * would leave pending requests in the driver's list with no way to clear
     * them once they withdrew the journey.
     */
    public function test_a_pending_request_may_be_declined_after_the_journey_was_cancelled(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        app(CancelRoute::class)($driver, $route->id);

        $result = $this->decline($driver, $this->id('01'));

        self::assertSame(SeatRequestStatus::Declined, $result->request->status);
    }

    public function test_a_pending_request_may_be_declined_after_the_journey_departed(): void
    {
        [$route, $passenger, $driver] = $this->asked();

        $result = $this->decline(
            $driver,
            $this->id('01'),
            now: CarbonImmutable::now()->addDays(30),
        );

        self::assertSame(SeatRequestStatus::Declined, $result->request->status);
    }

    public function test_a_driver_who_does_not_own_the_journey_cannot_decline(): void
    {
        $this->asked();
        $stranger = $this->driver('+905321119999');

        $this->expectException(ModelNotFoundException::class);

        $this->decline($stranger, $this->id('01'));
    }

    // -------------------------------------------------------------- accept

    public function test_a_driver_accepts_a_pending_request(): void
    {
        [$route, $passenger, $driver] = $this->asked();

        $result = $this->accept($driver, $this->id('01'));

        self::assertFalse($result->wasAlreadyInTargetState);
        self::assertSame(SeatRequestStatus::Accepted, $result->request->status);
        self::assertNotNull($result->request->decided_at);
        self::assertNull($result->request->withdrawn_at);
    }

    public function test_accepting_twice_writes_nothing_the_second_time(): void
    {
        [$route, $passenger, $driver] = $this->asked();

        $this->accept($driver, $this->id('01'));
        $before = $this->row($this->id('01'));

        $second = $this->accept($driver, $this->id('01'));
        $after = $this->row($this->id('01'));

        self::assertTrue($second->wasAlreadyInTargetState);
        self::assertEquals($before->updated_at, $after->updated_at);
        self::assertEquals($before->decided_at, $after->decided_at);
    }

    /**
     * THE ORDERING THIS COMMAND IS BUILT AROUND.
     *
     * The seat was given. A lost response, then a cancellation, then a retry —
     * and the retry must still say the seat was given, because it was. Checking
     * the route first would make a command that already succeeded start failing.
     */
    public function test_a_repeated_accept_still_succeeds_after_the_journey_was_cancelled(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->accept($driver, $this->id('01'));
        app(CancelRoute::class)($driver, $route->id);

        $retry = $this->accept($driver, $this->id('01'));

        self::assertTrue($retry->wasAlreadyInTargetState);
        self::assertSame(SeatRequestStatus::Accepted, $retry->request->status);
    }

    public function test_a_repeated_accept_still_succeeds_after_the_journey_departed(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->accept($driver, $this->id('01'));

        $retry = $this->accept(
            $driver,
            $this->id('01'),
            now: CarbonImmutable::now()->addDays(30),
        );

        self::assertTrue($retry->wasAlreadyInTargetState);
    }

    /** And after the remaining seats were given away. */
    public function test_a_repeated_accept_still_succeeds_when_the_journey_is_now_full(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, seats: 1);
        $first = $this->passenger('+905322220001');
        $this->ask($first, $this->id('01'), $route);

        $this->accept($driver, $this->id('01'));

        $retry = $this->accept($driver, $this->id('01'));

        self::assertTrue($retry->wasAlreadyInTargetState);
        self::assertSame(1, $this->acceptedCount($route));
    }

    public function test_a_declined_request_cannot_be_accepted(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->decline($driver, $this->id('01'));

        $refused = $this->refusal(fn () => $this->accept($driver, $this->id('01')));

        self::assertSame(RefusalReason::AlreadyDecided, $refused->reason);
    }

    public function test_a_withdrawn_request_cannot_be_accepted(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->withdraw($passenger, $this->id('01'));

        $refused = $this->refusal(fn () => $this->accept($driver, $this->id('01')));

        self::assertSame(RefusalReason::Withdrawn, $refused->reason);
    }

    /** A new seat cannot be given on a journey that is not running. */
    public function test_a_pending_request_on_a_cancelled_journey_cannot_be_accepted(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        app(CancelRoute::class)($driver, $route->id);

        $refused = $this->refusal(fn () => $this->accept($driver, $this->id('01')));

        self::assertSame(RefusalReason::RouteUnavailable, $refused->reason);
        self::assertSame(SeatRequestStatus::Pending, $this->row($this->id('01'))->status);
    }

    public function test_a_pending_request_on_a_departed_journey_cannot_be_accepted(): void
    {
        [$route, $passenger, $driver] = $this->asked();

        $refused = $this->refusal(fn () => $this->accept(
            $driver,
            $this->id('01'),
            now: CarbonImmutable::now()->addDays(30),
        ));

        self::assertSame(RefusalReason::RouteUnavailable, $refused->reason);
    }

    public function test_a_driver_who_does_not_own_the_journey_cannot_accept(): void
    {
        $this->asked();
        $stranger = $this->driver('+905321119999');

        $this->expectException(ModelNotFoundException::class);

        $this->accept($stranger, $this->id('01'));
    }

    // ------------------------------------------------------------ capacity

    public function test_the_last_offered_seat_is_still_acceptable(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, seats: 2);
        $this->ask($this->passenger('+905322220001'), $this->id('01'), $route);
        $this->ask($this->passenger('+905322220002'), $this->id('02'), $route);

        $this->accept($driver, $this->id('01'));
        $second = $this->accept($driver, $this->id('02'));

        self::assertSame(SeatRequestStatus::Accepted, $second->request->status);
        self::assertSame(2, $this->acceptedCount($route));
    }

    public function test_a_journey_whose_seats_are_all_accepted_is_full(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, seats: 1);
        $this->ask($this->passenger('+905322220001'), $this->id('01'), $route);
        $this->ask($this->passenger('+905322220002'), $this->id('02'), $route);

        $this->accept($driver, $this->id('01'));
        $refused = $this->refusal(fn () => $this->accept($driver, $this->id('02')));

        self::assertSame(RefusalReason::RouteFull, $refused->reason);
        self::assertSame(SeatRequestStatus::Pending, $this->row($this->id('02'))->status);
        self::assertSame(1, $this->acceptedCount($route));
    }

    /** Asking is not taking: twenty pending requests reserve nothing. */
    public function test_pending_requests_do_not_consume_capacity(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, seats: 1);
        $this->ask($this->passenger('+905322220001'), $this->id('01'), $route);
        $this->ask($this->passenger('+905322220002'), $this->id('02'), $route);
        $this->ask($this->passenger('+905322220003'), $this->id('03'), $route);

        $result = $this->accept($driver, $this->id('03'));

        self::assertSame(SeatRequestStatus::Accepted, $result->request->status);
    }

    /**
     * The invariant, driven only through the command.
     *
     * Nothing here writes a status directly: if the command path could ever
     * oversubscribe, this is where it would show.
     */
    public function test_the_command_path_cannot_exceed_the_offered_seats(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, seats: 2);
        foreach (['01', '02', '03', '04'] as $index => $tail) {
            $this->ask($this->passenger('+90532222000'.$index), $this->id($tail), $route);
        }

        $accepted = 0;
        $full = 0;
        foreach (['01', '02', '03', '04'] as $tail) {
            try {
                $this->accept($driver, $this->id($tail));
                $accepted++;
            } catch (SeatRequestRefused $refused) {
                self::assertSame(RefusalReason::RouteFull, $refused->reason);
                $full++;
            }
        }

        self::assertSame(2, $accepted);
        self::assertSame(2, $full);
        self::assertSame(2, $this->acceptedCount($route));
        self::assertLessThanOrEqual($route->seats_offered, $this->acceptedCount($route));
    }

    /** A withdrawn or declined request never counted, so it frees nothing. */
    public function test_only_accepted_requests_count_against_capacity(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, seats: 1);
        $this->ask($this->passenger('+905322220001'), $this->id('01'), $route);
        $this->ask($this->passenger('+905322220002'), $this->id('02'), $route);

        $this->decline($driver, $this->id('01'));

        $result = $this->accept($driver, $this->id('02'));

        self::assertSame(SeatRequestStatus::Accepted, $result->request->status);
    }

    // -------------------------------------------------- structural proofs

    /**
     * Accept locks the request before the route, and both are locked.
     *
     * A true two-connection race cannot be run under `RefreshDatabase` — the
     * suite holds one open transaction on one connection, so a competing
     * transaction would block rather than interleave. The guarantee is
     * therefore proved structurally: the order below is what makes the
     * sequential capacity tests above hold under concurrency, and if either
     * lock disappears or the order inverts, this fails.
     *
     * The inversion matters beyond capacity: `request → route` is the only
     * two-resource order in the domain, and a path taking them the other way
     * round would make a deadlock with nothing else reachable.
     */
    public function test_accept_locks_the_request_before_the_route(): void
    {
        [$route, $passenger, $driver] = $this->asked();

        $statements = $this->statementsDuring(fn () => $this->accept($driver, $this->id('01')));

        $requestLock = $this->lockOn($statements, 'seat_requests');
        $routeLock = $this->lockOn($statements, 'routes');

        self::assertNotNull($requestLock, 'the seat request was read without a lock');
        self::assertNotNull($routeLock, 'the route was read without a lock');
        self::assertLessThan($routeLock, $requestLock, 'the route was locked before the request');
    }

    /** The capacity count happens after the route lock, never before it. */
    public function test_capacity_is_counted_after_the_route_is_locked(): void
    {
        [$route, $passenger, $driver] = $this->asked();

        $statements = $this->statementsDuring(fn () => $this->accept($driver, $this->id('01')));

        $routeLock = $this->lockOn($statements, 'routes');
        $count = $this->capacityCount($statements);

        self::assertNotNull($routeLock);
        self::assertNotNull($count, 'capacity was never counted');
        self::assertLessThan($count, $routeLock, 'capacity was counted before the lock');
    }

    /**
     * A repeated accept touches no route at all.
     *
     * Which is both why it survives a cancellation and why it cannot contribute
     * a `route → request` ordering to the lock graph.
     */
    public function test_a_repeated_accept_locks_no_route(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->accept($driver, $this->id('01'));

        $statements = $this->statementsDuring(fn () => $this->accept($driver, $this->id('01')));

        self::assertNull(
            $this->lockOn($statements, 'routes'),
            'a repeated accept locked a route',
        );
    }

    /** Withdraw and decline never reach a route either. */
    public function test_withdraw_and_decline_lock_no_route(): void
    {
        [$route, $passenger, $driver] = $this->asked();
        $this->ask($this->passenger('+905322220002'), $this->id('02'), $route);

        $withdrawing = $this->statementsDuring(
            fn () => $this->withdraw($passenger, $this->id('01')),
        );
        $declining = $this->statementsDuring(
            fn () => $this->decline($driver, $this->id('02')),
        );

        self::assertNull($this->lockOn($withdrawing, 'routes'));
        self::assertNull($this->lockOn($declining, 'routes'));
    }

    // --------------------------------------------- capacity is per journey

    /**
     * CARRIES WEIGHT. A full Monday does not close Tuesday.
     *
     * `seats_offered` is what the driver offers on each journey the plan makes,
     * so accepted passengers on one date say nothing about another's capacity.
     * Counting across every date would let one full day shut a recurring plan
     * for every other — and with one seat offered, the very first acceptance
     * would close it for ever.
     *
     * Reached synthetically because both recurrence guards stand: the accepted
     * row on the other date is planted, which is the shape 16b makes ordinary.
     */
    public function test_a_full_date_does_not_consume_another_dates_seat(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, seats: 1);
        $passenger = $this->passenger();
        $this->ask($passenger, $this->id('01'), $route);

        // Yesterday's journey is full. Today's has not been touched.
        $this->plantAccepted(
            $this->id('09'),
            $route,
            $this->passenger('+905322220009'),
            $route->soleServiceDate()->subDay(),
        );

        $accepted = $this->accept($driver, $this->id('01'));

        self::assertSame(SeatRequestStatus::Accepted, $accepted->request->status);
    }

    /** And the seat it does consume is still counted on its own date. */
    public function test_the_offered_seat_is_still_consumed_within_one_date(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver, seats: 1);
        $first = $this->passenger();
        $second = $this->passenger('+905322220002');

        $this->ask($first, $this->id('01'), $route);
        $this->ask($second, $this->id('02'), $route);

        $this->accept($driver, $this->id('01'));

        self::assertSame(
            RefusalReason::RouteFull,
            $this->refusal(fn () => $this->accept($driver, $this->id('02')))->reason,
        );
    }

    // ------------------------------------------------------------- fixtures

    /**
     * An accepted request on a date of this route, written straight to the row.
     *
     * No command can produce a date other than the route's while the recurrence
     * guards stand, so the dated capacity predicate is proved with a row rather
     * than by weakening a guard to reach it.
     */
    private function plantAccepted(
        string $requestId,
        Route $route,
        Account $passenger,
        CarbonImmutable $serviceDate,
    ): void {
        DB::table('seat_requests')->insert([
            'id' => $requestId,
            'route_id' => $route->id,
            'account_id' => $passenger->id,
            'service_date' => $serviceDate->toDateString(),
            'status' => SeatRequestStatus::Accepted->value,
            'requested_at' => CarbonImmutable::now(),
            'decided_at' => CarbonImmutable::now(),
            'withdrawn_at' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * A published one-off journey with one pending request on it.
     *
     * @return array{0: Route, 1: Account, 2: Account}
     */
    private function asked(): array
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $passenger = $this->passenger();
        $this->ask($passenger, $this->id('01'), $route);

        return [$route, $passenger, $driver];
    }

    private function ask(Account $passenger, string $requestId, Route $route): void
    {
        app(RequestSeat::class)($passenger, $requestId, $route->id);
    }

    private function withdraw(
        Account $passenger,
        string $requestId,
        ?CarbonImmutable $now = null,
    ): TransitionedSeatRequest {
        return app(WithdrawSeatRequest::class)($passenger, $requestId, $now);
    }

    private function decline(
        Account $driver,
        string $requestId,
        ?CarbonImmutable $now = null,
    ): TransitionedSeatRequest {
        return app(DeclineSeatRequest::class)($driver, $requestId, $now);
    }

    private function accept(
        Account $driver,
        string $requestId,
        ?CarbonImmutable $now = null,
    ): TransitionedSeatRequest {
        return app(AcceptSeatRequest::class)($driver, $requestId, $now);
    }

    private function refusal(callable $command): SeatRequestRefused
    {
        try {
            $command();
        } catch (SeatRequestRefused $refused) {
            return $refused;
        }

        self::fail('the command was not refused');
    }

    private function row(string $requestId): SeatRequest
    {
        return SeatRequest::query()->findOrFail($requestId);
    }

    private function acceptedCount(Route $route): int
    {
        return SeatRequest::query()
            ->where('route_id', $route->id)
            ->where('status', SeatRequestStatus::Accepted->value)
            ->count();
    }

    /** @return list<string> */
    private function statementsDuring(callable $command): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $command();
        DB::disableQueryLog();

        // array_values, because array_map preserves keys and the return type
        // promises a list.
        return array_values(array_map(
            static fn (array $entry): string => strtolower((string) $entry['query']),
            DB::getQueryLog(),
        ));
    }

    /**
     * Where a `FOR UPDATE` on one table appears in the statement log.
     *
     * Matched on the statement's own FROM rather than anywhere in the text: the
     * driver-scoped lookup reads
     * `select * from "seat_requests" where exists (select * from "routes" …) for update`,
     * which names routes in a subquery while locking only the seat request. A
     * substring match would read that as a route lock and quietly invert the
     * meaning of every assertion below.
     *
     * @param  list<string>  $statements
     */
    private function lockOn(array $statements, string $table): ?int
    {
        foreach ($statements as $index => $statement) {
            if (str_starts_with($statement, 'select * from "'.$table.'"')
                && str_contains($statement, 'for update')) {
                return $index;
            }
        }

        return null;
    }

    /** @param  list<string>  $statements */
    private function capacityCount(array $statements): ?int
    {
        foreach ($statements as $index => $statement) {
            if (str_contains($statement, 'count(*)')
                && str_contains($statement, 'from "seat_requests"')) {
                return $index;
            }
        }

        return null;
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

    private function route(Account $driver, int $seats = 3): Route
    {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            $this->routeId('01'),
            $this->place(self::KADIKOY),
            $this->place(self::LEVENT),
            RouteDeparture::fromInput(
                Recurrence::Once,
                CarbonImmutable::now()->addDays(3)->format('Y-m-d'),
                '08:00',
                $timezone,
            ),
            $seats,
            new RideRules(noSmoking: true, musicOk: false, noPets: false, quiet: false),
        )->route;
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
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
