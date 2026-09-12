<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Review;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Reviews\RefusalReason;
use App\Reviews\ReviewerRole;
use App\Reviews\ReviewRefused;
use App\Reviews\ReviewWindow;
use App\Reviews\SubmitReview;
use App\Reviews\SubmittedReview;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use App\SeatRequests\SeatRequestStatus;
use App\Trips\TripStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * Rating a completed relationship, and everything that refuses one.
 *
 * The two rules worth the most here are the ordering ones. A supplied id is
 * resolved before the seat request's status, the trip's state and the clock, so
 * a review that landed a minute before the deadline keeps replaying afterwards.
 * And the seat request is checked before the journey, because a declined
 * request names no shared journey at all — answering `trip_not_completed` for
 * one would be a true-sounding statement about the wrong thing.
 *
 * Nothing here means anybody boarded. A completed trip is a driver pressing a
 * button, which is the only fact the service owns.
 */
final class SubmitReviewTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private CarbonImmutable $completedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
        // Frozen, and every expectation below is measured from it. Nothing
        // reads the machine clock.
        $this->completedAt = CarbonImmutable::parse('2026-09-12T09:00:00Z');
    }

    // ------------------------------------------------------- what it accepts

    public function test_a_passenger_rates_the_driver_of_a_completed_journey(): void
    {
        $world = $this->world();

        $result = $this->submit($world, $world->passenger, rating: 5);

        self::assertFalse($result->wasAlreadySubmitted);
        self::assertSame(ReviewerRole::Passenger, $result->review->reviewer_role);
        self::assertSame(5, $result->review->rating);
        self::assertSame($world->request->id, $result->review->seat_request_id);
        self::assertSame(1, Review::query()->count());
    }

    public function test_a_driver_rates_an_accepted_passenger(): void
    {
        $world = $this->world();

        $result = $this->submit($world, $world->driver, rating: 4);

        self::assertSame(ReviewerRole::Driver, $result->review->reviewer_role);
        self::assertSame(4, $result->review->rating);
    }

    /**
     * CARRIES WEIGHT. Bilateral and independent: neither blocks the other.
     */
    public function test_both_sides_may_each_rate_the_same_relationship(): void
    {
        $world = $this->world();

        $this->submit($world, $world->passenger, rating: 5);
        $this->submit($world, $world->driver, rating: 3, reviewId: $this->id('02'));

        self::assertSame(2, Review::query()->count());
        self::assertSame(
            [ReviewerRole::Driver, ReviewerRole::Passenger],
            Review::query()
                ->orderBy('reviewer_role')
                ->get()
                ->map(fn (Review $r): ReviewerRole => $r->reviewer_role)
                ->all(),
        );
    }

    /**
     * CARRIES WEIGHT. Route status is not an eligibility gate.
     *
     * Withdrawing a plan says nothing about a journey that was already made.
     * The combination is unreachable through the commands today — cancelling
     * needs an upcoming departure and starting needs a reached one — so this
     * builds it directly, which is exactly why the rule is worth having.
     */
    public function test_a_withdrawn_route_does_not_take_the_journey_away(): void
    {
        $world = $this->world();
        DB::table('routes')
            ->where('id', $world->route->id)
            ->update(['status' => 'cancelled', 'cancelled_at' => $this->completedAt]);

        $result = $this->submit($world, $world->passenger, rating: 5);

        self::assertSame(5, $result->review->rating);
    }

    // ------------------------------------------------------- the ordering

    /**
     * CARRIES WEIGHT. Identity before mutable eligibility.
     *
     * A review that landed a minute before the deadline must keep replaying
     * afterwards. Checking the window first would make a command that already
     * succeeded begin to fail — the defect this ordering exists to prevent.
     */
    public function test_a_replay_still_works_after_the_window_has_closed(): void
    {
        $world = $this->world();
        $first = $this->submit($world, $world->passenger, rating: 5);

        $late = $this->submit(
            $world,
            $world->passenger,
            rating: 5,
            now: ReviewWindow::closesAt($this->completedAt)->addDay(),
        );

        self::assertTrue($late->wasAlreadySubmitted);
        self::assertSame($first->review->id, $late->review->id);
        self::assertSame(1, Review::query()->count());
    }

    /**
     * CARRIES WEIGHT. A declined request names no shared journey, and saying
     * `trip_not_completed` about one would be true of the wrong thing — the
     * journey may well have completed, with somebody else aboard.
     */
    public function test_the_relationship_is_judged_before_the_journey(): void
    {
        $world = $this->world(requestStatus: SeatRequestStatus::Declined);

        // The trip IS completed here, so only the ordering produces this.
        $this->assertRefused(
            fn () => $this->submit($world, $world->passenger, rating: 5),
            RefusalReason::SeatRequestNotAccepted,
        );
    }

    // ------------------------------------------------- what it refuses, and why

    /**
     * @return iterable<string, array{0: SeatRequestStatus}>
     */
    public static function unacceptedStatuses(): iterable
    {
        yield 'nobody answered' => [SeatRequestStatus::Pending];
        yield 'the driver said no' => [SeatRequestStatus::Declined];
        yield 'the passenger took it back' => [SeatRequestStatus::Withdrawn];
    }

    #[DataProvider('unacceptedStatuses')]
    public function test_only_an_agreed_seat_may_be_rated(SeatRequestStatus $status): void
    {
        $world = $this->world(requestStatus: $status);

        $this->assertRefused(
            fn () => $this->submit($world, $world->passenger, rating: 5),
            RefusalReason::SeatRequestNotAccepted,
        );
    }

    /**
     * @return iterable<string, array{0: ?TripStatus}>
     */
    public static function unfinishedJourneys(): iterable
    {
        yield 'nobody started it' => [null];
        yield 'it is under way' => [TripStatus::InProgress];
        yield 'it was abandoned' => [TripStatus::Aborted];
    }

    #[DataProvider('unfinishedJourneys')]
    public function test_only_a_completed_journey_may_be_rated(?TripStatus $status): void
    {
        $world = $this->world(tripStatus: $status);

        $this->assertRefused(
            fn () => $this->submit($world, $world->passenger, rating: 5),
            RefusalReason::TripNotCompleted,
        );
    }

    // ------------------------------------------------------------ the window

    public function test_a_moment_before_the_deadline_is_still_open(): void
    {
        $world = $this->world();

        $result = $this->submit(
            $world,
            $world->passenger,
            rating: 5,
            now: ReviewWindow::closesAt($this->completedAt)->subSecond(),
        );

        self::assertFalse($result->wasAlreadySubmitted);
    }

    /**
     * CARRIES WEIGHT. The deadline itself is closed, not open.
     */
    public function test_the_deadline_itself_is_closed(): void
    {
        $world = $this->world();

        $this->assertRefused(
            fn () => $this->submit(
                $world,
                $world->passenger,
                rating: 5,
                now: ReviewWindow::closesAt($this->completedAt),
            ),
            RefusalReason::ReviewWindowClosed,
        );
    }

    public function test_after_the_deadline_is_closed(): void
    {
        $world = $this->world();

        $this->assertRefused(
            fn () => $this->submit(
                $world,
                $world->passenger,
                rating: 5,
                now: ReviewWindow::closesAt($this->completedAt)->addDays(30),
            ),
            RefusalReason::ReviewWindowClosed,
        );
    }

    // -------------------------------------------------------- the same id

    public function test_an_exact_replay_writes_nothing(): void
    {
        $world = $this->world();
        $first = $this->submit($world, $world->passenger, rating: 5);

        $again = $this->submit($world, $world->passenger, rating: 5);

        self::assertTrue($again->wasAlreadySubmitted);
        self::assertSame($first->review->id, $again->review->id);
        self::assertTrue(
            $first->review->updated_at->equalTo($again->review->updated_at),
            'a replay must not touch the row',
        );
        self::assertSame(1, Review::query()->count());
    }

    public function test_the_same_id_with_a_different_rating_is_a_different_review(): void
    {
        $world = $this->world();
        $this->submit($world, $world->passenger, rating: 5);

        $this->assertRefused(
            fn () => $this->submit($world, $world->passenger, rating: 4),
            RefusalReason::IdAlreadyUsed,
        );
    }

    public function test_the_same_id_on_another_relationship_is_refused(): void
    {
        $world = $this->world();
        $other = $this->world(
            passengerPhone: '+905322220001',
            routeTail: '02',
            requestTail: '02',
        );
        $this->submit($world, $world->passenger, rating: 5);

        $this->assertRefused(
            fn () => $this->submit($other, $other->passenger, rating: 5),
            RefusalReason::IdAlreadyUsed,
        );
    }

    /** The other party reusing an id is the same collision. */
    public function test_the_same_id_from_the_other_side_is_refused(): void
    {
        $world = $this->world();
        $this->submit($world, $world->passenger, rating: 5);

        $this->assertRefused(
            fn () => $this->submit($world, $world->driver, rating: 5),
            RefusalReason::IdAlreadyUsed,
        );
    }

    /**
     * CARRIES WEIGHT. One review per party per relationship, whatever id it
     * arrives under.
     */
    public function test_a_second_review_under_a_fresh_id_is_refused(): void
    {
        $world = $this->world();
        $this->submit($world, $world->passenger, rating: 5);

        $this->assertRefused(
            fn () => $this->submit($world, $world->passenger, rating: 3, reviewId: $this->id('02')),
            RefusalReason::AlreadyReviewed,
        );
        self::assertSame(1, Review::query()->count());
    }

    // ------------------------------------------------------ who may ask at all

    /**
     * CARRIES WEIGHT. A stranger learns nothing — not even that the
     * relationship exists.
     */
    public function test_a_member_party_to_neither_side_gets_a_plain_not_found(): void
    {
        $world = $this->world();
        $stranger = $this->member('+905329990000', 'Deniz Kaya');

        $this->expectException(ModelNotFoundException::class);

        $this->submit($world, $stranger, rating: 5);
    }

    public function test_a_relationship_that_does_not_exist_answers_the_same(): void
    {
        $world = $this->world();

        $this->expectException(ModelNotFoundException::class);

        app(SubmitReview::class)(
            $world->passenger,
            $this->id('01'),
            $this->requestId('ff'),
            5,
            $this->completedAt,
        );
    }

    // ---------------------------------------------- the race, classified

    /**
     * CARRIES WEIGHT. The insert loses a real race, and still answers well.
     *
     * A second connection cannot be run under `RefreshDatabase`, which holds
     * one transaction on one connection. So the racing row is written from
     * inside a query listener, at the exact point a competing writer would have
     * committed: AFTER this command's own reads found nothing and BEFORE its
     * insert. Writing it beforehand would prove nothing — the command's first
     * read would find it and never reach the collision path at all, which is
     * how the first version of these two tests passed while proving nothing.
     *
     * Same id, same canonical identity: this is the same review arriving twice,
     * so the collision must resolve to a replay rather than to a refusal.
     */
    public function test_losing_the_race_to_the_same_review_replays(): void
    {
        $world = $this->world();
        $this->raceIn($world, id: $this->id('01'), rating: 5);

        $result = $this->submit($world, $world->passenger, rating: 5);

        self::assertTrue($result->wasAlreadySubmitted);
        self::assertSame($this->id('01'), $result->review->id);
        self::assertSame(1, Review::query()->count());
    }

    /**
     * And losing it to the OTHER id is the relationship being spoken for.
     */
    public function test_losing_the_race_to_another_id_is_already_reviewed(): void
    {
        $world = $this->world();
        $this->raceIn($world, id: $this->id('99'), rating: 2);

        $this->assertRefused(
            fn () => $this->submit($world, $world->passenger, rating: 5),
            RefusalReason::AlreadyReviewed,
        );
        self::assertSame(1, Review::query()->count());
    }

    /**
     * Writes a competing review the moment this command finishes looking.
     *
     * Hooks the duplicate check — the last read before the insert — so the row
     * lands in the window a real second connection would have used. Fires once.
     */
    private function raceIn(_World $world, string $id, int $rating): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $world, $id, $rating): void {
            if ($fired || ! str_contains($query->sql, 'reviewer_role')) {
                return;
            }

            $fired = true;

            DB::table('reviews')->insert([
                'id' => $id,
                'seat_request_id' => $world->request->id,
                'reviewer_role' => 'passenger',
                'rating' => $rating,
                'created_at' => $this->completedAt,
                'updated_at' => $this->completedAt,
            ]);
        });
    }

    // ------------------------------------------ one journey is not another

    /**
     * CARRIES WEIGHT. Another date's completed journey does not make this one
     * reviewable.
     *
     * Eligibility asks whether the journey THIS asking was accepted onto was
     * completed. Resolving the trip by route alone would accept any journey the
     * plan ever made — so a member accepted for Tuesday could rate a stranger
     * on the strength of Monday having run. That is the whole reason the lookup
     * is dated.
     *
     * Built with planted rows because no command in 16a can make a second
     * journey on one route, and no guard was weakened to reach it.
     */
    public function test_another_dates_completed_journey_does_not_make_this_reviewable(): void
    {
        $world = $this->world(tripStatus: null);
        $this->plantCompletedTripOn($world, $world->route->soleServiceDate()->subDay());

        $this->assertRefused(
            fn () => $this->submit($world, $world->passenger, 5),
            RefusalReason::TripNotCompleted,
        );
    }

    /** And completing the journey the asking is for does make it reviewable. */
    public function test_completing_this_dates_journey_makes_it_reviewable(): void
    {
        $world = $this->world(tripStatus: null);
        $this->plantCompletedTripOn($world, $world->route->soleServiceDate()->subDay());
        $this->plantCompletedTripOn($world, $world->route->soleServiceDate(), tail: 'fe');

        $submitted = $this->submit($world, $world->passenger, 5);

        self::assertSame(5, $submitted->review->rating);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * A finished journey on one date of this world's route.
     *
     * Written straight to the row: the dated eligibility lookup cannot be
     * proved through a command while both recurrence guards stand.
     */
    private function plantCompletedTripOn(
        _World $world,
        CarbonImmutable $serviceDate,
        string $tail = 'ff',
    ): void {
        DB::table('trips')->insert([
            'id' => $this->tripId($tail),
            'route_id' => $world->route->id,
            'service_date' => $serviceDate->toDateString(),
            'status' => TripStatus::Completed->value,
            'started_at' => $this->completedAt->subHour(),
            'completed_at' => $this->completedAt,
            'aborted_at' => null,
            'created_at' => $this->completedAt->subHour(),
            'updated_at' => $this->completedAt,
        ]);
    }

    private function submit(
        _World $world,
        Account $reviewer,
        int $rating,
        ?string $reviewId = null,
        ?CarbonImmutable $now = null,
    ): SubmittedReview {
        return app(SubmitReview::class)(
            $reviewer,
            $reviewId ?? $this->id('01'),
            $world->request->id,
            $rating,
            $now ?? $this->completedAt->addHour(),
        );
    }

    private function assertRefused(callable $act, RefusalReason $expected): void
    {
        try {
            $act();
        } catch (ReviewRefused $refused) {
            self::assertSame($expected, $refused->reason);

            return;
        }

        self::fail("expected {$expected->value}, and nothing was refused");
    }

    /**
     * A driver, a passenger, a journey and the relationship between them.
     */
    private function world(
        SeatRequestStatus $requestStatus = SeatRequestStatus::Accepted,
        ?TripStatus $tripStatus = TripStatus::Completed,
        string $passengerPhone = '+905322220000',
        string $routeTail = '01',
        string $requestTail = '01',
    ): _World {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $passenger = $this->member($passengerPhone, 'Ayşe Demir');
        $route = $this->route($driver, $this->routeId($routeTail));

        DB::table('seat_requests')->insert([
            'id' => $this->requestId($requestTail),
            'route_id' => $route->id,
            'service_date' => $route->departure_date,
            'account_id' => $passenger->id,
            'status' => $requestStatus->value,
            'requested_at' => $this->completedAt->subDays(4),
            'decided_at' => $requestStatus === SeatRequestStatus::Accepted
                || $requestStatus === SeatRequestStatus::Declined
                ? $this->completedAt->subDays(3)
                : null,
            'withdrawn_at' => $requestStatus === SeatRequestStatus::Withdrawn
                ? $this->completedAt->subDays(3)
                : null,
            'created_at' => $this->completedAt->subDays(4),
            'updated_at' => $this->completedAt->subDays(4),
        ]);

        if ($tripStatus !== null) {
            DB::table('trips')->insert([
                'id' => $this->tripId($routeTail),
                'route_id' => $route->id,
                'service_date' => $route->departure_date,
                'status' => $tripStatus->value,
                'started_at' => $this->completedAt->subHour(),
                'completed_at' => $tripStatus === TripStatus::Completed
                    ? $this->completedAt
                    : null,
                'aborted_at' => $tripStatus === TripStatus::Aborted
                    ? $this->completedAt
                    : null,
                'created_at' => $this->completedAt->subHour(),
                'updated_at' => $this->completedAt,
            ]);
        }

        return new _World(
            driver: $driver,
            passenger: $passenger,
            route: $route,
            request: SeatRequest::query()->findOrFail($this->requestId($requestTail)),
        );
    }

    private function member(string $phone, string $name): Account
    {
        $existing = Account::query()->where('phone_e164', $phone)->first();
        if ($existing instanceof Account) {
            return $existing;
        }

        $account = $this->createAccount($phone);
        (new SaveProfile)($account, DisplayName::fromInput($name));

        return $account;
    }

    private function route(Account $driver, string $routeId): Route
    {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            $routeId,
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

    private function id(string $tail): string
    {
        return '01993a00-0000-7000-8000-0000000000'.$tail;
    }

    private function requestId(string $tail): string
    {
        return '01991d00-0000-7000-8000-0000000000'.$tail;
    }

    private function routeId(string $tail): string
    {
        return '01991c00-0000-7000-8000-0000000000'.$tail;
    }

    private function tripId(string $tail): string
    {
        return '01991e00-0000-7000-8000-0000000000'.$tail;
    }
}

/** The cast of one scenario, so a test reads as a situation rather than setup. */
final readonly class _World
{
    public function __construct(
        public Account $driver,
        public Account $passenger,
        public Route $route,
        public SeatRequest $request,
    ) {}
}
