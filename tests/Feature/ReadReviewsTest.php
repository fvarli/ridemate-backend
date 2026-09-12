<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Profile;
use App\Models\Review;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Reviews\ListMyReviews;
use App\Reviews\MyReview;
use App\Reviews\MyReviewLookup;
use App\Reviews\ReviewerRole;
use App\Reviews\ReviewPage;
use App\Reviews\ReviewWindow;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use App\Support\KeysetCursor;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * Who may read a review, and when.
 *
 * The rule this file exists for: a review is hidden from the member it is about
 * until the other side writes one too, or the window closes. Before then it
 * must be absent — not flagged, not counted, not a gap in a page, and not
 * something a cursor names.
 *
 * The property worth the most is the one about pagination. A hidden review that
 * consumed a page slot would leak its own existence through the shape of the
 * result, which is exactly what waiting for the counterpart is supposed to
 * prevent.
 */
final class ReadReviewsTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private CarbonImmutable $completedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
        $this->completedAt = CarbonImmutable::parse('2026-09-12T09:00:00Z');
    }

    // ------------------------------------------------------------ visibility

    /**
     * CARRIES WEIGHT. Before the counterpart writes, there is nothing to read.
     */
    public function test_an_unmatched_review_is_hidden_from_its_subject(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Passenger, 5);

        $page = $this->about($world->driver, at: $this->completedAt->addHour());

        self::assertSame([], $page->reviews);
        self::assertNull($page->nextCursor);
    }

    /**
     * CARRIES WEIGHT. The deadline itself releases.
     *
     * The mirror of submission, which closes at the same instant — so there is
     * no moment when a review can neither be written nor read.
     */
    public function test_the_deadline_itself_releases_an_unmatched_review(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Passenger, 5);

        $justBefore = $this->about(
            $world->driver,
            at: ReviewWindow::closesAt($this->completedAt)->subSecond(),
        );
        self::assertSame([], $justBefore->reviews);

        $exactly = $this->about(
            $world->driver,
            at: ReviewWindow::closesAt($this->completedAt),
        );
        self::assertCount(1, $exactly->reviews);

        $after = $this->about(
            $world->driver,
            at: ReviewWindow::closesAt($this->completedAt)->addDays(30),
        );
        self::assertCount(1, $after->reviews);
    }

    /**
     * CARRIES WEIGHT. The counterpart releases both at once.
     */
    public function test_a_counterpart_releases_both_sides_immediately(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Passenger, 5);
        $this->review($world, ReviewerRole::Driver, 4, tail: '02');

        $at = $this->completedAt->addHour();

        self::assertCount(1, $this->about($world->driver, at: $at)->reviews);
        self::assertCount(1, $this->about($world->passenger, at: $at)->reviews);
    }

    /** A member never reads their own rating back as something said about them. */
    public function test_an_author_does_not_receive_their_own_review(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Passenger, 5);
        $this->review($world, ReviewerRole::Driver, 4, tail: '02');

        $page = $this->about($world->passenger, at: $this->completedAt->addHour());

        self::assertCount(1, $page->reviews);
        self::assertSame(ReviewerRole::Driver, $page->reviews[0]->role());
    }

    public function test_a_stranger_reads_neither(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Passenger, 5);
        $this->review($world, ReviewerRole::Driver, 4, tail: '02');
        $stranger = $this->member('+905329990000', 'Deniz Kaya');

        self::assertSame(
            [],
            $this->about($stranger, at: $this->completedAt->addDays(60))->reviews,
        );
    }

    // -------------------------------------------------------- directionality

    /**
     * CARRIES WEIGHT. The subject is derived, and the row names nobody.
     */
    public function test_each_review_reaches_the_other_party_and_only_them(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Driver, 5);
        $at = ReviewWindow::closesAt($this->completedAt);

        $toPassenger = $this->about($world->passenger, at: $at);
        self::assertCount(1, $toPassenger->reviews);
        self::assertSame(ReviewerRole::Driver, $toPassenger->reviews[0]->role());

        self::assertSame([], $this->about($world->driver, at: $at)->reviews);
    }

    public function test_a_passenger_review_reaches_the_driver(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Passenger, 5);
        $at = ReviewWindow::closesAt($this->completedAt);

        $toDriver = $this->about($world->driver, at: $at);
        self::assertCount(1, $toDriver->reviews);
        self::assertSame(ReviewerRole::Passenger, $toDriver->reviews[0]->role());

        self::assertSame([], $this->about($world->passenger, at: $at)->reviews);
    }

    // ------------------------------------------------------------ projection

    public function test_the_projection_carries_the_author_and_the_journey(): void
    {
        $world = $this->world();
        $review = $this->review($world, ReviewerRole::Passenger, 4);

        $page = $this->about($world->driver, at: ReviewWindow::closesAt($this->completedAt));
        $received = $page->reviews[0];

        self::assertSame($review->id, $received->review->id);
        self::assertSame(4, $received->review->rating);
        // Submitted is created: a review is never edited, so there is one
        // instant and this is it.
        self::assertTrue($received->review->created_at->equalTo($review->created_at));

        self::assertSame('Ayşe Demir', $received->reviewer->display_name);
        self::assertSame('AD', $received->reviewer->initials());

        self::assertSame($world->route->id, $received->route->id);
        self::assertSame('Kadıköy, Vapur İskelesi', $received->route->originPlace->label);
        self::assertSame('Levent, Metro İstasyonu', $received->route->destinationPlace->label);
        self::assertSame(Recurrence::Once, $received->route->recurrence);
    }

    /** The reviewer is a profile, so no credential can travel with it. */
    public function test_the_projection_carries_no_account(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Passenger, 5);

        $received = $this->about(
            $world->driver,
            at: ReviewWindow::closesAt($this->completedAt),
        )->reviews[0];

        // The exact property set: an Account added beside these would be a
        // phone number one careless payload away, and this is what would fail.
        self::assertSame(
            ['review', 'reviewer', 'route'],
            array_keys(get_object_vars($received)),
        );
        self::assertInstanceOf(Profile::class, $received->reviewer);
        self::assertArrayNotHasKey('phone_e164', $received->reviewer->toArray());
    }

    // ------------------------------------------------------------ my_review

    public function test_my_review_is_null_before_the_caller_writes_one(): void
    {
        $world = $this->world();

        self::assertNull($this->mine($world, $world->passenger));
    }

    public function test_my_review_carries_what_the_caller_submitted(): void
    {
        $world = $this->world();
        $review = $this->review($world, ReviewerRole::Passenger, 3);

        $mine = $this->mine($world, $world->passenger);

        self::assertInstanceOf(MyReview::class, $mine);
        self::assertSame($review->id, $mine->id);
        self::assertSame(3, $mine->rating);
        self::assertTrue($mine->submittedAt->equalTo($review->created_at));
    }

    /**
     * CARRIES WEIGHT. The counterpart's submission is invisible here.
     *
     * A caller must not be able to tell "they have not reviewed me" from "they
     * have, and I may not see it yet" — knowing would bias the rating they are
     * about to write, which is the whole reason release waits.
     */
    public function test_my_review_says_nothing_about_the_other_side(): void
    {
        $alone = $this->world();
        $this->review($alone, ReviewerRole::Driver, 5);

        $both = $this->world(
            passengerPhone: '+905322220001',
            routeTail: '02',
            requestTail: '02',
        );
        $this->review($both, ReviewerRole::Driver, 5, tail: '03');
        $this->review($both, ReviewerRole::Passenger, 2, tail: '04');

        // The driver has written on both. What the passenger did differs, and
        // the driver's own projection must not show it.
        $first = $this->mine($alone, $alone->driver);
        $second = $this->mine($both, $both->driver);

        self::assertInstanceOf(MyReview::class, $first);
        self::assertInstanceOf(MyReview::class, $second);
        self::assertSame(
            array_keys(get_object_vars($first)),
            array_keys(get_object_vars($second)),
        );
        self::assertSame(['id', 'rating', 'submittedAt'], array_keys(get_object_vars($first)));
    }

    public function test_many_relationships_are_read_in_one_query(): void
    {
        $first = $this->world();
        $second = $this->world(
            passengerPhone: '+905322220001',
            routeTail: '02',
            requestTail: '02',
        );
        $this->review($first, ReviewerRole::Driver, 5);
        $this->review($second, ReviewerRole::Driver, 4, tail: '02');

        // Loaded BEFORE the log opens: fetching the relationships is the
        // caller's own read, and counting it here would measure the fixture
        // rather than the lookup.
        $requests = [$this->loaded($first->request), $this->loaded($second->request)];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $mine = app(MyReviewLookup::class)->forMany($requests, $first->driver);
        DB::disableQueryLog();

        self::assertCount(2, $mine);
        self::assertSame(
            1,
            count(DB::getQueryLog()),
            'a page of relationships must not become a read per row',
        );
    }

    /**
     * CARRIES WEIGHT. A page fetch must return the caller's own row, never the
     * counterpart's wearing it.
     *
     * The rows come back in one query, so both sides' reviews arrive together;
     * the wrong one being kept would hand a member the rating somebody gave
     * THEM as though they had written it — and would leak, before release, that
     * the counterpart had written anything at all.
     */
    public function test_a_page_never_returns_the_counterparts_review_as_mine(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Driver, 5, tail: '01');
        $this->review($world, ReviewerRole::Passenger, 2, tail: '02');

        $requests = [$this->loaded($world->request)];

        $driverSees = app(MyReviewLookup::class)->forMany($requests, $world->driver);
        $passengerSees = app(MyReviewLookup::class)->forMany($requests, $world->passenger);

        self::assertCount(1, $driverSees);
        self::assertCount(1, $passengerSees);
        self::assertSame(5, $driverSees[$world->request->id]->rating);
        self::assertSame(2, $passengerSees[$world->request->id]->rating);
    }

    /**
     * And where only the counterpart has written, a page says nothing at all.
     */
    public function test_a_page_is_silent_when_only_the_other_side_has_written(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Passenger, 2);

        $mine = app(MyReviewLookup::class)->forMany(
            [$this->loaded($world->request)],
            $world->driver,
        );

        self::assertSame([], $mine);
    }

    /** A relationship the caller is party to neither side of has no answer. */
    public function test_a_stranger_has_no_my_review(): void
    {
        $world = $this->world();
        $this->review($world, ReviewerRole::Driver, 5);
        $stranger = $this->member('+905329990000', 'Deniz Kaya');

        self::assertNull($this->mine($world, $stranger));
    }

    // ----------------------------------------------------------- pagination

    /**
     * CARRIES WEIGHT. A hidden review is not a candidate, so it cannot take a
     * slot, cannot shorten a page, and cannot be what a cursor names.
     */
    public function test_a_hidden_review_consumes_no_page_slot(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');

        // Three released, one hidden between them.
        $this->relationship($driver, '01', '+905322220001', 'Ayşe Demir', released: true, tail: '01');
        $this->relationship($driver, '02', '+905322220002', 'Bora Çelik', released: false, tail: '03');
        $this->relationship($driver, '03', '+905322220003', 'Ceren Ak', released: true, tail: '05');
        $this->relationship($driver, '04', '+905322220004', 'Demir Su', released: true, tail: '07');

        $page = $this->about($driver, at: $this->completedAt->addHour(), limit: 3);

        self::assertCount(3, $page->reviews, 'the page fills across the hidden row');
        self::assertNull(
            $page->nextCursor,
            'there is nothing released after the third, so there is no next page',
        );
    }

    public function test_the_cursor_names_the_last_returned_review(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $this->relationship($driver, '01', '+905322220001', 'Ayşe Demir', released: true, tail: '01');
        $this->relationship($driver, '02', '+905322220002', 'Bora Çelik', released: false, tail: '03');
        $this->relationship($driver, '03', '+905322220003', 'Ceren Ak', released: true, tail: '05');

        $first = $this->about($driver, at: $this->completedAt->addHour(), limit: 1);

        self::assertCount(1, $first->reviews);
        self::assertInstanceOf(KeysetCursor::class, $first->nextCursor);
        self::assertSame(
            $first->reviews[0]->review->id,
            $first->nextCursor->id,
            'the cursor must name a row the caller actually saw',
        );

        $second = $this->about(
            $driver,
            at: $this->completedAt->addHour(),
            limit: 1,
            cursor: $first->nextCursor,
        );

        self::assertCount(1, $second->reviews);
        self::assertNotSame($first->reviews[0]->review->id, $second->reviews[0]->review->id);
        self::assertNull($second->nextCursor);
    }

    public function test_rows_arrive_newest_first_and_never_twice(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        for ($i = 1; $i <= 4; $i++) {
            $this->relationship(
                $driver,
                str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                '+90532222000'.$i,
                'Yolcu '.$i,
                released: true,
                tail: str_pad((string) ($i * 2), 2, '0', STR_PAD_LEFT),
                submittedAt: $this->completedAt->addMinutes($i),
            );
        }

        $seen = [];
        $cursor = null;
        do {
            $page = $this->about(
                $driver,
                at: $this->completedAt->addHour(),
                limit: 2,
                cursor: $cursor,
            );
            foreach ($page->reviews as $received) {
                $seen[] = $received->review->id;
            }
            $cursor = $page->nextCursor;
        } while ($cursor instanceof KeysetCursor);

        self::assertCount(4, $seen);
        self::assertSame($seen, array_values(array_unique($seen)), 'no row twice');
        // Newest first: the last relationship written has the latest instant.
        self::assertSame($this->reviewId('08'), $seen[0]);
        self::assertSame($this->reviewId('02'), $seen[3]);
    }

    // ------------------------------------------------------------- fixtures

    private function about(
        Account $subject,
        CarbonImmutable $at,
        int $limit = ListMyReviews::DEFAULT_LIMIT,
        ?KeysetCursor $cursor = null,
    ): ReviewPage {
        return app(ListMyReviews::class)($subject, $cursor, $limit, $at);
    }

    private function mine(_ReadWorld $world, Account $caller): ?MyReview
    {
        return app(MyReviewLookup::class)->for($this->loaded($world->request), $caller);
    }

    private function loaded(SeatRequest $request): SeatRequest
    {
        return SeatRequest::query()
            ->with(['route.account', 'passenger'])
            ->findOrFail($request->id);
    }

    private function review(
        _ReadWorld $world,
        ReviewerRole $role,
        int $rating,
        string $tail = '01',
        ?CarbonImmutable $submittedAt = null,
    ): Review {
        DB::table('reviews')->insert([
            'id' => $this->reviewId($tail),
            'seat_request_id' => $world->request->id,
            'reviewer_role' => $role->value,
            'rating' => $rating,
            'created_at' => $submittedAt ?? $this->completedAt->addMinutes(5),
            'updated_at' => $submittedAt ?? $this->completedAt->addMinutes(5),
        ]);

        return Review::query()->findOrFail($this->reviewId($tail));
    }

    /**
     * A whole relationship with one review on it, released or not.
     *
     * Released means the counterpart wrote one too; hidden means they did not,
     * and the window is still open.
     */
    private function relationship(
        Account $driver,
        string $routeTail,
        string $passengerPhone,
        string $passengerName,
        bool $released,
        string $tail,
        ?CarbonImmutable $submittedAt = null,
    ): void {
        $world = $this->world(
            driver: $driver,
            passengerPhone: $passengerPhone,
            passengerName: $passengerName,
            routeTail: $routeTail,
            requestTail: $routeTail,
        );

        $this->review($world, ReviewerRole::Passenger, 5, $tail, $submittedAt);

        if ($released) {
            $this->review(
                $world,
                ReviewerRole::Driver,
                4,
                str_pad((string) ((int) $tail + 1), 2, '0', STR_PAD_LEFT),
                $submittedAt,
            );
        }
    }

    private function world(
        ?Account $driver = null,
        string $passengerPhone = '+905322220000',
        string $passengerName = 'Ayşe Demir',
        string $routeTail = '01',
        string $requestTail = '01',
    ): _ReadWorld {
        $driver ??= $this->member('+905321110000', 'İrem Yılmaz');
        $passenger = $this->member($passengerPhone, $passengerName);
        $route = $this->route($driver, $this->routeId($routeTail));

        DB::table('seat_requests')->insert([
            'id' => $this->requestId($requestTail),
            'route_id' => $route->id,
            'service_date' => $route->departure_date,
            'account_id' => $passenger->id,
            'status' => 'accepted',
            'requested_at' => $this->completedAt->subDays(4),
            'decided_at' => $this->completedAt->subDays(3),
            'withdrawn_at' => null,
            'created_at' => $this->completedAt->subDays(4),
            'updated_at' => $this->completedAt->subDays(4),
        ]);

        DB::table('trips')->insert([
            'id' => $this->tripId($routeTail),
            'route_id' => $route->id,
            'service_date' => $route->departure_date,
            'status' => 'completed',
            'started_at' => $this->completedAt->subHour(),
            'completed_at' => $this->completedAt,
            'created_at' => $this->completedAt->subHour(),
            'updated_at' => $this->completedAt,
        ]);

        return new _ReadWorld(
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

    private function reviewId(string $tail): string
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

/** The cast of one read scenario. */
final readonly class _ReadWorld
{
    public function __construct(
        public Account $driver,
        public Account $passenger,
        public Route $route,
        public SeatRequest $request,
    ) {}
}
