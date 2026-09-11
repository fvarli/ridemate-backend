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
use App\Reviews\ReviewParticipants;
use App\Reviews\ReviewWindow;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * What the database refuses about a review.
 *
 * Every write goes through `DB::table` rather than a domain action, and that is
 * the point: the command arrives in B2, and if it were the only thing keeping
 * these invariants true then a console command or a hand-written UPDATE could
 * break them without a test noticing.
 *
 * The invariant worth the most here is that one party reviews one relationship
 * once — and that there is nowhere in the row to write a reviewer who is not a
 * party to it.
 */
final class ReviewPersistenceTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    // ------------------------------------------------------------- the shape

    public function test_a_review_persists_as_a_rating_on_one_relationship(): void
    {
        $request = $this->seatRequest();

        $this->insert($request, ReviewerRole::Passenger, 5);

        $review = Review::query()->sole();

        self::assertSame($request->id, $review->seat_request_id);
        self::assertSame(ReviewerRole::Passenger, $review->reviewer_role);
        self::assertSame(5, $review->rating);
    }

    /**
     * CARRIES WEIGHT. One review per party per relationship.
     */
    public function test_the_same_party_cannot_review_one_relationship_twice(): void
    {
        $request = $this->seatRequest();
        $this->insert($request, ReviewerRole::Passenger, 5);

        $this->expectException(QueryException::class);

        $this->insert($request, ReviewerRole::Passenger, 4, id: $this->id('02'));
    }

    /** And both sides may, independently. */
    public function test_one_relationship_holds_exactly_two_reviews(): void
    {
        $request = $this->seatRequest();

        $this->insert($request, ReviewerRole::Passenger, 5);
        $this->insert($request, ReviewerRole::Driver, 4, id: $this->id('02'));

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

    /** A different relationship is a different pair of reviews. */
    public function test_the_same_role_may_review_a_different_relationship(): void
    {
        $first = $this->seatRequest();
        $second = $this->seatRequest(
            passengerPhone: '+905322220001',
            routeTail: '02',
            requestTail: '02',
        );

        $this->insert($first, ReviewerRole::Passenger, 5);
        $this->insert($second, ReviewerRole::Passenger, 3, id: $this->id('03'));

        self::assertSame(2, Review::query()->count());
    }

    // ------------------------------------------------------ what it refuses

    /**
     * ONE CASE PER TEST, AND THAT IS LOAD-BEARING.
     *
     * A loop would prove almost nothing here. PostgreSQL aborts the whole
     * transaction on the first failed statement, and `RefreshDatabase` wraps
     * each test in one — so every attempt after the first throws whatever its
     * value, and a widened constraint passes unnoticed. A mutation raising the
     * ceiling to ten survived exactly that way before this was split up.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function invalidRoles(): iterable
    {
        yield 'a third kind of member' => ['member'];
        yield 'both at once' => ['both'];
        yield 'the service itself' => ['system'];
        yield 'the right word, shouted' => ['DRIVER'];
        yield 'nothing at all' => [''];
        yield 'a word from another domain' => ['owner'];
    }

    #[DataProvider('invalidRoles')]
    public function test_a_role_the_contract_does_not_name_is_refused(string $role): void
    {
        $request = $this->seatRequest();

        $this->expectException(QueryException::class);

        $this->insert($request, $role, 5);
    }

    /**
     * CARRIES WEIGHT. One to five, whole.
     *
     * @return iterable<string, array{0: int}>
     */
    public static function invalidRatings(): iterable
    {
        yield 'below the floor' => [0];
        yield 'above the ceiling' => [6];
        yield 'negative' => [-1];
        yield 'far above' => [100];
    }

    #[DataProvider('invalidRatings')]
    public function test_a_rating_outside_one_to_five_is_refused(int $rating): void
    {
        $request = $this->seatRequest();

        $this->expectException(QueryException::class);

        $this->insert($request, ReviewerRole::Passenger, $rating);
    }

    public function test_both_ends_of_the_scale_are_accepted(): void
    {
        $request = $this->seatRequest();

        $this->insert($request, ReviewerRole::Passenger, 1);
        $this->insert($request, ReviewerRole::Driver, 5, id: $this->id('02'));

        self::assertSame(
            [1, 5],
            Review::query()->orderBy('rating')->pluck('rating')->all(),
        );
    }

    public function test_a_review_of_no_relationship_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('reviews')->insert([
            'id' => $this->id('01'),
            'seat_request_id' => $this->requestId('ff'),
            'reviewer_role' => 'passenger',
            'rating' => 5,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /** The relationship going away takes its reviews with it. */
    public function test_reviews_do_not_outlive_the_relationship(): void
    {
        $request = $this->seatRequest();
        $this->insert($request, ReviewerRole::Passenger, 5);

        DB::table('seat_requests')->where('id', $request->id)->delete();

        self::assertSame(0, Review::query()->count());
    }

    // ------------------------------------------ who wrote it, and about whom

    /**
     * CARRIES WEIGHT. The schema stores no account, so this is the only way to
     * know — and there is exactly one of it.
     */
    public function test_the_two_parties_are_derived_from_the_relationship(): void
    {
        $request = $this->seatRequest();
        $participants = ReviewParticipants::of($request);

        self::assertSame($request->route->account_id, $participants->driver->id);
        self::assertSame($request->account_id, $participants->passenger->id);

        self::assertSame(
            ReviewerRole::Driver,
            $participants->roleOf($participants->driver),
        );
        self::assertSame(
            ReviewerRole::Passenger,
            $participants->roleOf($participants->passenger),
        );
    }

    /** A member who is party to neither side is neither, and never a refusal. */
    public function test_a_stranger_has_no_role_at_all(): void
    {
        $request = $this->seatRequest();
        $stranger = $this->createAccount('+905329990000');

        self::assertNull(ReviewParticipants::of($request)->roleOf($stranger));
    }

    public function test_a_review_is_about_the_other_party(): void
    {
        $request = $this->seatRequest();
        $participants = ReviewParticipants::of($request);

        self::assertSame(
            $participants->passenger->id,
            $participants->subjectOf(ReviewerRole::Driver)->id,
        );
        self::assertSame(
            $participants->driver->id,
            $participants->subjectOf(ReviewerRole::Passenger)->id,
        );
        self::assertSame(
            $participants->driver->id,
            $participants->authorOf(ReviewerRole::Driver)->id,
        );
    }

    // ------------------------------------------------------------ the window

    public function test_the_window_is_fourteen_days_from_the_completion(): void
    {
        $completed = CarbonImmutable::parse('2026-09-12T09:00:00Z');

        self::assertSame(14, ReviewWindow::DAYS);
        self::assertTrue(
            ReviewWindow::closesAt($completed)->equalTo(
                CarbonImmutable::parse('2026-09-26T09:00:00Z'),
            ),
        );

        self::assertTrue(ReviewWindow::isOpen($completed, $completed));
        self::assertTrue(
            ReviewWindow::isOpen($completed, $completed->addDays(14)->subSecond()),
        );
        self::assertFalse(ReviewWindow::isOpen($completed, $completed->addDays(14)));
        self::assertTrue(ReviewWindow::hasClosed($completed, $completed->addDays(14)));
    }

    // ------------------------------------------------------- the vocabulary

    /**
     * Five reasons, and the wire strings are contract.
     */
    public function test_the_refusal_vocabulary_is_exactly_five_reasons(): void
    {
        self::assertSame([
            'seat_request_not_accepted',
            'trip_not_completed',
            'review_window_closed',
            'already_reviewed',
            'id_already_used',
        ], array_column(RefusalReason::cases(), 'value'));
    }

    public function test_a_role_is_one_of_two_and_knows_its_counterpart(): void
    {
        self::assertSame(
            ['driver', 'passenger'],
            array_column(ReviewerRole::cases(), 'value'),
        );

        self::assertSame(ReviewerRole::Passenger, ReviewerRole::Driver->counterpart());
        self::assertSame(ReviewerRole::Driver, ReviewerRole::Passenger->counterpart());
    }

    // ------------------------------------------------------------- fixtures

    private function seatRequest(
        string $passengerPhone = '+905322220000',
        string $routeTail = '01',
        string $requestTail = '01',
    ): SeatRequest {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $passenger = $this->member($passengerPhone, 'Ayşe Demir');
        $route = $this->route($driver, $this->routeId($routeTail));

        DB::table('seat_requests')->insert([
            'id' => $this->requestId($requestTail),
            'route_id' => $route->id,
            'account_id' => $passenger->id,
            'status' => 'accepted',
            'requested_at' => CarbonImmutable::now(),
            'decided_at' => CarbonImmutable::now(),
            'withdrawn_at' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        // With both parties loaded, because that is how a caller reaches
        // ReviewParticipants — the request alone cannot name the driver.
        return SeatRequest::query()
            ->with(['route.account', 'passenger'])
            ->findOrFail($this->requestId($requestTail));
    }

    private function insert(
        SeatRequest $request,
        ReviewerRole|string $role,
        int $rating,
        ?string $id = null,
    ): void {
        DB::table('reviews')->insert([
            'id' => $id ?? $this->id('01'),
            'seat_request_id' => $request->id,
            'reviewer_role' => $role instanceof ReviewerRole ? $role->value : $role,
            'rating' => $rating,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * The member with this number, created once.
     *
     * A driver holds several journeys, so a test building two relationships
     * asks for the same driver twice — and a second `createAccount` would
     * collide on the phone number rather than returning the member.
     */
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
}
