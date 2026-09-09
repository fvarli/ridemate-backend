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
use App\SeatRequests\RefusalReason;
use App\SeatRequests\RequestSeat;
use App\SeatRequests\SeatRequested;
use App\SeatRequests\SeatRequestRefused;
use App\SeatRequests\SeatRequestStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * Asking for a seat, and everything that must not happen while asking.
 *
 * The HTTP layer is a later commit; this proves the domain under it, so the
 * controller has nothing left to decide except a status code.
 *
 * The two hardest cases are here rather than deferred to an integration test:
 * a retry arriving after the world changed, and a second asking arriving under
 * a different id.
 */
final class SeatRequestCreationTest extends TestCase
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

    // ------------------------------------------------------------ asking

    public function test_a_new_request_is_pending_and_belongs_to_the_caller(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $result = $this->ask($passenger, $this->id('01'), $route);

        self::assertFalse($result->wasAlreadyRequested);
        self::assertSame(SeatRequestStatus::Pending, $result->request->status);
        self::assertSame($passenger->id, $result->request->account_id);
        self::assertSame($route->id, $result->request->route_id);
        // The server's clock, not the client's: the domain sets it.
        self::assertTrue($result->request->requested_at->isBetween(
            CarbonImmutable::now()->subMinute(),
            CarbonImmutable::now()->addMinute(),
        ));
        self::assertNull($result->request->decided_at);
        self::assertNull($result->request->withdrawn_at);
        self::assertSame(1, SeatRequest::query()->count());
    }

    /**
     * The client's id is the idempotency key, so the application must persist
     * it unchanged rather than leaning on the model's own generator.
     */
    public function test_the_supplied_id_is_persisted_exactly(): void
    {
        $route = $this->route($this->driver());

        $result = $this->ask($this->passenger(), $this->id('ab'), $route);

        self::assertSame($this->id('ab'), $result->request->id);
        self::assertSame($this->id('ab'), SeatRequest::query()->sole()->id);
    }

    public function test_two_members_may_ask_about_the_same_journey(): void
    {
        $route = $this->route($this->driver());

        $this->ask($this->passenger('+905322220001'), $this->id('01'), $route);
        $this->ask($this->passenger('+905322220002'), $this->id('02'), $route);

        self::assertSame(2, SeatRequest::query()->count());
    }

    // ------------------------------------------------------- eligibility

    public function test_a_caller_without_a_profile_is_refused(): void
    {
        $route = $this->route($this->driver());
        // An account that verified a phone number and never chose a name.
        $nameless = $this->createAccount('+905323330000');

        $refused = $this->refusal($nameless, $this->id('01'), $route);

        self::assertSame(RefusalReason::ProfileRequired, $refused->reason);
        self::assertSame(0, SeatRequest::query()->count());
    }

    public function test_a_driver_cannot_ask_for_a_seat_in_their_own_car(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        $refused = $this->refusal($driver, $this->id('01'), $route);

        self::assertSame(RefusalReason::OwnRoute, $refused->reason);
        self::assertSame(0, SeatRequest::query()->count());
    }

    public function test_a_weekday_plan_cannot_be_requested(): void
    {
        $route = $this->route($this->driver(), recurrence: Recurrence::Weekdays);

        $refused = $this->refusal($this->passenger(), $this->id('01'), $route);

        self::assertSame(RefusalReason::RecurringRouteUnsupported, $refused->reason);
        self::assertSame(0, SeatRequest::query()->count());
    }

    /**
     * Cancelled and departed journeys answer the way an unknown id does.
     *
     * Not a distinguishable refusal: discovery shows neither to a passenger, so
     * a specific answer here would confirm which route ids exist.
     */
    public function test_a_cancelled_journey_is_not_disclosed(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        app(CancelRoute::class)($driver, $route->id);

        $this->expectException(ModelNotFoundException::class);

        $this->ask($this->passenger(), $this->id('01'), $route);
    }

    public function test_a_departed_one_off_is_not_disclosed(): void
    {
        $route = $this->route($this->driver());

        $this->expectException(ModelNotFoundException::class);

        // The clock moved past the departure; nothing about the row changed.
        $this->ask(
            $this->passenger(),
            $this->id('01'),
            $route,
            now: CarbonImmutable::now()->addDays(30),
        );
    }

    public function test_an_unknown_route_is_not_disclosed(): void
    {
        $passenger = $this->passenger();

        $this->expectException(ModelNotFoundException::class);

        app(RequestSeat::class)($passenger, $this->id('01'), $this->routeId('ff'));
    }

    // -------------------------------------------------------- idempotency

    public function test_the_same_id_from_the_same_member_returns_the_same_request(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $first = $this->ask($passenger, $this->id('01'), $route);
        $second = $this->ask($passenger, $this->id('01'), $route);

        self::assertFalse($first->wasAlreadyRequested);
        self::assertTrue($second->wasAlreadyRequested);
        self::assertSame($first->request->id, $second->request->id);
        self::assertSame(1, SeatRequest::query()->count());
        // Not even a timestamp: a retry is one asking arriving twice.
        self::assertEquals(
            $first->request->updated_at,
            $second->request->fresh()?->updated_at,
        );
    }

    /**
     * THE CASE THE ORDERING EXISTS FOR.
     *
     * The original response was lost, the driver cancelled, and the retry
     * arrives. Judging it against the world as it now stands would tell the
     * passenger their asking failed while it sits in the database.
     */
    public function test_a_retry_still_succeeds_after_the_journey_was_cancelled(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $passenger = $this->passenger();

        $first = $this->ask($passenger, $this->id('01'), $route);
        app(CancelRoute::class)($driver, $route->id);

        $retry = $this->ask($passenger, $this->id('01'), $route);

        self::assertTrue($retry->wasAlreadyRequested);
        self::assertSame($first->request->id, $retry->request->id);
    }

    /** The same, with the clock rather than a cancellation. */
    public function test_a_retry_still_succeeds_after_the_journey_departed(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $this->ask($passenger, $this->id('01'), $route);

        $retry = $this->ask(
            $passenger,
            $this->id('01'),
            $route,
            now: CarbonImmutable::now()->addDays(30),
        );

        self::assertTrue($retry->wasAlreadyRequested);
    }

    /**
     * A retry of a request that has since been answered returns the answer.
     *
     * The terminal state is seeded directly: the commands that produce it are
     * B3's, and this is about the create path, not about them.
     */
    public function test_a_retry_returns_a_request_that_has_since_become_terminal(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $this->ask($passenger, $this->id('01'), $route);
        $this->makeTerminal($this->id('01'), SeatRequestStatus::Declined);

        $retry = $this->ask($passenger, $this->id('01'), $route);

        self::assertTrue($retry->wasAlreadyRequested);
        self::assertSame(SeatRequestStatus::Declined, $retry->request->status);
        self::assertSame(1, SeatRequest::query()->count());
    }

    public function test_reusing_an_id_for_a_different_journey_is_refused(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();
        $first = $this->route($driver);
        $other = $this->route($driver, routeId: $this->routeId('02'), destination: self::MASLAK);

        $this->ask($passenger, $this->id('01'), $first);

        $refused = $this->refusal($passenger, $this->id('01'), $other);

        self::assertSame(RefusalReason::IdAlreadyUsed, $refused->reason);
        self::assertSame(1, SeatRequest::query()->count());
    }

    /**
     * Somebody else's id says only that it is taken.
     *
     * No owner, no route, no status: the refusal must be indistinguishable from
     * the one above, or it becomes a way to probe other members' requests.
     */
    public function test_reusing_another_members_id_leaks_nothing(): void
    {
        $route = $this->route($this->driver());
        $mine = $this->passenger('+905322220001');
        $theirs = $this->passenger('+905322220002');

        $this->ask($theirs, $this->id('01'), $route);

        $refused = $this->refusal($mine, $this->id('01'), $route);

        self::assertSame(RefusalReason::IdAlreadyUsed, $refused->reason);
        self::assertNull($refused->existing);
        self::assertStringNotContainsString($theirs->id, $refused->getMessage());
        self::assertStringNotContainsString($route->id, $refused->getMessage());
        self::assertSame(1, SeatRequest::query()->count());
    }

    /**
     * The lifetime uniqueness, reached through the savepoint recovery.
     *
     * The row already exists under another id, so the insert loses on
     * `(route_id, account_id)` and the outer transaction — and the route lock —
     * must survive long enough to work out why. This exercises the real
     * PostgreSQL constraint, not a pre-check.
     */
    public function test_asking_again_under_a_different_id_is_refused(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $first = $this->ask($passenger, $this->id('01'), $route);

        $refused = $this->refusal($passenger, $this->id('02'), $route);

        self::assertSame(RefusalReason::AlreadyRequested, $refused->reason);
        // The caller's own request, so telling them where it stands is theirs.
        self::assertSame($first->request->id, $refused->existing?->id);
        self::assertSame(1, SeatRequest::query()->count());
    }

    public function test_a_spent_request_does_not_let_the_member_ask_again(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        $this->ask($passenger, $this->id('01'), $route);
        $this->makeTerminal($this->id('01'), SeatRequestStatus::Withdrawn);

        $refused = $this->refusal($passenger, $this->id('02'), $route);

        self::assertSame(RefusalReason::AlreadyRequested, $refused->reason);
        self::assertSame(1, SeatRequest::query()->count());
    }

    /**
     * A violation neither row explains is a defect, and stays one.
     *
     * The recovery reads the table to decide which uniqueness broke. If it
     * treated "a unique constraint failed" as sufficient evidence of a business
     * conflict, an unrelated integrity bug would answer 409 and look like
     * ordinary contention forever.
     *
     * Forced with a real constraint rather than a mock: an extra unique index
     * that the domain knows nothing about, so the insert genuinely fails on
     * PostgreSQL and neither the supplied id nor this member's row accounts for
     * it. `RefreshDatabase` rolls the index back with everything else.
     */
    public function test_an_unexplained_integrity_violation_is_not_a_business_conflict(): void
    {
        $route = $this->route($this->driver());
        $this->ask($this->passenger('+905322220001'), $this->id('01'), $route);

        DB::statement('create unique index tmp_one_request_per_route on seat_requests (route_id)');

        // A different member, a different id: nothing about this asking is a
        // duplicate as far as the domain's own rules are concerned.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->ask($this->passenger('+905322220002'), $this->id('02'), $route);
    }

    // ---------------------------------------------------------- capacity

    /**
     * Asking is not taking.
     *
     * A one-seat journey whose only seat is already accepted still accepts a
     * new asking: seats bind when a driver accepts, and refusing here would
     * decide on the driver's behalf.
     */
    public function test_asking_neither_inspects_nor_reserves_capacity(): void
    {
        $route = $this->route($this->driver(), seats: 1);
        $taken = $this->passenger('+905322220001');
        $asking = $this->passenger('+905322220002');

        $this->ask($taken, $this->id('01'), $route);
        $this->makeTerminal($this->id('01'), SeatRequestStatus::Accepted);

        $result = $this->ask($asking, $this->id('02'), $route);

        self::assertFalse($result->wasAlreadyRequested);
        self::assertSame(SeatRequestStatus::Pending, $result->request->status);
    }

    // ------------------------------------------------ serialization proof

    /**
     * The route row is locked before the decision that reads it.
     *
     * This is what serializes a create against `CancelRoute`, whose own
     * transaction takes the same row. A true two-connection race cannot be run
     * under `RefreshDatabase` — the suite holds one open transaction on one
     * connection, so a second connection would block on it rather than
     * interleave — so the guarantee is proved structurally instead: if the
     * `for update` disappears, or the eligibility read stops going through it,
     * this fails.
     */
    public function test_the_route_is_locked_before_a_new_request_is_created(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->ask($passenger, $this->id('01'), $route);
        DB::disableQueryLog();

        /** @var list<string> $statements */
        $statements = array_map(
            static fn (array $entry): string => strtolower((string) $entry['query']),
            DB::getQueryLog(),
        );

        $lockedAt = null;
        $insertedAt = null;
        foreach ($statements as $index => $statement) {
            if (str_contains($statement, 'from "routes"') && str_contains($statement, 'for update')) {
                $lockedAt ??= $index;
            }
            if (str_contains($statement, 'insert into "seat_requests"')) {
                $insertedAt ??= $index;
            }
        }

        self::assertNotNull($lockedAt, 'the route was read without a lock');
        self::assertNotNull($insertedAt, 'no seat request was inserted');
        self::assertLessThan($insertedAt, $lockedAt, 'the lock was taken after the insert');
    }

    /**
     * An existing-id retry never reaches for a route at all.
     *
     * Two things at once: it is why a retry survives a cancellation, and it is
     * why this path cannot take a route lock while holding a request lock —
     * which is what would put a `route → request` order into the system and
     * make a deadlock with accept's `request → route` possible.
     */
    public function test_a_retry_locks_no_route(): void
    {
        $route = $this->route($this->driver());
        $passenger = $this->passenger();
        $this->ask($passenger, $this->id('01'), $route);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->ask($passenger, $this->id('01'), $route);
        DB::disableQueryLog();

        foreach (DB::getQueryLog() as $entry) {
            $statement = strtolower((string) $entry['query']);
            self::assertFalse(
                str_contains($statement, 'from "routes"') && str_contains($statement, 'for update'),
                'a retry locked a route, which would invert the request → route order',
            );
        }
    }

    // ------------------------------------------------------------ fixtures

    private function ask(
        Account $passenger,
        string $requestId,
        Route $route,
        ?CarbonImmutable $now = null,
    ): SeatRequested {
        return app(RequestSeat::class)($passenger, $requestId, $route->id, $now);
    }

    private function refusal(
        Account $passenger,
        string $requestId,
        Route $route,
    ): SeatRequestRefused {
        try {
            $this->ask($passenger, $requestId, $route);
        } catch (SeatRequestRefused $refused) {
            return $refused;
        }

        self::fail('the request was not refused');
    }

    /**
     * Puts a request into a terminal state without B3's commands.
     *
     * Written straight to the row, shape constraints satisfied, because the
     * transitions themselves are the next commit and this file must not depend
     * on them.
     */
    private function makeTerminal(string $requestId, SeatRequestStatus $status): void
    {
        DB::table('seat_requests')->where('id', $requestId)->update([
            'status' => $status->value,
            'decided_at' => $status === SeatRequestStatus::Withdrawn ? null : CarbonImmutable::now(),
            'withdrawn_at' => $status === SeatRequestStatus::Withdrawn ? CarbonImmutable::now() : null,
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
        ?string $routeId = null,
        string $destination = self::LEVENT,
        Recurrence $recurrence = Recurrence::Once,
        int $seats = 3,
    ): Route {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            $routeId ?? $this->routeId('01'),
            $this->place(self::KADIKOY),
            $this->place($destination),
            RouteDeparture::fromInput(
                $recurrence,
                $recurrence === Recurrence::Once
                    ? CarbonImmutable::now()->addDays(3)->format('Y-m-d')
                    : null,
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
