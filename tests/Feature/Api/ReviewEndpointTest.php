<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Place;
use App\Reviews\RefusalReason;
use App\Reviews\ReviewWindow;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Contract\ValidatesTheContract;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * Reviews over HTTP.
 *
 * The domain is already proven by SubmitReviewTest and ReadReviewsTest; this is
 * about what the wire says — which status each outcome carries, which
 * machine-readable reason a refusal names, and what a response is careful not
 * to contain.
 *
 * Two things this file exists to stop. A payload that undoes the release rule
 * by publishing what the query carefully excluded; and `my_review` widening
 * past the two listings that are allowed to carry it, which is the Phase 14
 * mistake wearing a different field name.
 */
final class ReviewEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;
    use ValidatesTheContract;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'accounts',
        'auth_sessions',
        'auth_tokens',
        'otp_challenges',
        'profiles',
        'reviews',
        'routes',
        'seat_requests',
        'trips',
    ];

    private CarbonImmutable $departure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTestSmsSender();
        $this->seed(PilotPlaceSeeder::class);

        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);
        // Two minutes out, so travelling past it stays inside the access
        // token's fifteen-minute life.
        $this->departure = CarbonImmutable::now($timezone)->addMinutes(2);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------------------ submitting

    public function test_reviewing_requires_a_credential(): void
    {
        $this->postJson('/api/v1/seat-requests/'.$this->requestId(1).'/review', [
            'id' => $this->reviewId(1),
            'rating' => 5,
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_first_review_answers_201(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        $response = $this->review($passenger, rating: 5);

        $response->assertStatus(201)
            ->assertJsonPath('review.id', $this->reviewId(1))
            ->assertJsonPath('review.rating', 5);

        self::assertIsString($response->json('review.submitted_at'));
        $this->assertMatchesOperation(
            $response,
            '/api/v1/seat-requests/{requestId}/review',
            'post',
        );
    }

    public function test_a_retry_answers_200_with_the_same_review(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $first = $this->review($passenger, rating: 5)->assertStatus(201);

        $again = $this->review($passenger, rating: 5);

        $again->assertStatus(200)
            ->assertJsonPath('review.id', $first->json('review.id'))
            ->assertJsonPath('review.submitted_at', $first->json('review.submitted_at'));

        $this->assertMatchesOperation(
            $again,
            '/api/v1/seat-requests/{requestId}/review',
            'post',
        );
    }

    /**
     * CARRIES WEIGHT. Three fields, and nothing that identifies anybody.
     */
    public function test_the_submission_response_is_exactly_the_caller_own_review(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        $body = $this->review($passenger, rating: 4)->assertStatus(201)->json();
        self::assertIsArray($body);

        self::assertSame(['review'], array_keys($body));
        self::assertIsArray($body['review']);
        self::assertSame(['id', 'rating', 'submitted_at'], array_keys($body['review']));

        $this->assertNothingForbidden($body);
    }

    public function test_the_role_cannot_be_chosen_by_the_caller(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        $this->postJson(
            '/api/v1/seat-requests/'.$this->requestId(1).'/review',
            ['id' => $this->reviewId(1), 'rating' => 5, 'reviewer_role' => 'driver'],
            $passenger,
        )->assertStatus(422);
    }

    public function test_a_rating_outside_the_scale_is_a_validation_failure(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        foreach ([0, 6, 'five', null] as $rating) {
            $this->postJson(
                '/api/v1/seat-requests/'.$this->requestId(1).'/review',
                ['id' => $this->reviewId(1), 'rating' => $rating],
                $passenger,
            )->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
    }

    public function test_a_review_id_that_is_not_a_uuid_v7_is_refused(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        $this->postJson(
            '/api/v1/seat-requests/'.$this->requestId(1).'/review',
            ['id' => 'not-a-uuid', 'rating' => 5],
            $passenger,
        )->assertStatus(422);
    }

    // ----------------------------------------------------- who may ask at all

    /**
     * CARRIES WEIGHT. A stranger learns nothing, not even that it exists.
     */
    public function test_a_non_party_gets_the_same_404_as_a_missing_relationship(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $stranger = $this->member('+905329990000', 'Deniz Kaya');

        foreach ([$this->requestId(1), $this->requestId(9)] as $target) {
            $response = $this->postJson(
                "/api/v1/seat-requests/$target/review",
                ['id' => $this->reviewId(1), 'rating' => 5],
                $stranger,
            );

            $response->assertStatus(404)
                ->assertJsonPath('error.code', 'not_found')
                ->assertJsonPath('error.message', 'The requested resource was not found.')
                ->assertJsonMissingPath('error.details');

            $this->assertMatchesOperation(
                $response,
                '/api/v1/seat-requests/{requestId}/review',
                'post',
            );
        }
    }

    public function test_a_malformed_request_id_is_an_ordinary_404(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        $this->postJson(
            '/api/v1/seat-requests/not-a-uuid/review',
            ['id' => $this->reviewId(1), 'rating' => 5],
            $passenger,
        )->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    // ------------------------------------------------------------- refusals

    public function test_an_unagreed_seat_names_its_own_reason(): void
    {
        [$driver, $passenger] = $this->completedJourney(accept: false);

        $this->assertRefused(
            $this->review($passenger, rating: 5),
            RefusalReason::SeatRequestNotAccepted,
        );
    }

    public function test_an_unfinished_journey_names_its_own_reason(): void
    {
        [$driver, $passenger] = $this->completedJourney(complete: false);

        $this->assertRefused(
            $this->review($passenger, rating: 5),
            RefusalReason::TripNotCompleted,
        );
    }

    public function test_a_closed_window_names_its_own_reason(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->travelPastTheWindow();

        // Fourteen days outruns a fifteen-minute access token, so the member
        // signs in again — as they would have to in life.
        $passenger = $this->bearer($this->signIn('+905322220000')['access_token']);

        $this->assertRefused(
            $this->review($passenger, rating: 5),
            RefusalReason::ReviewWindowClosed,
        );
    }

    public function test_a_second_review_under_a_fresh_id_names_its_own_reason(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->review($passenger, rating: 5)->assertStatus(201);

        $this->assertRefused(
            $this->review($passenger, rating: 3, reviewTail: 2),
            RefusalReason::AlreadyReviewed,
        );
    }

    public function test_a_reused_id_naming_a_different_review_says_so(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->review($passenger, rating: 5)->assertStatus(201);

        $this->assertRefused(
            $this->review($passenger, rating: 2),
            RefusalReason::IdAlreadyUsed,
        );
    }

    /**
     * CARRIES WEIGHT. Every reason the domain owns reaches the wire.
     */
    public function test_every_documented_reason_is_reachable_over_http(): void
    {
        $proven = [
            'seat_request_not_accepted' => 'test_an_unagreed_seat_names_its_own_reason',
            'trip_not_completed' => 'test_an_unfinished_journey_names_its_own_reason',
            'review_window_closed' => 'test_a_closed_window_names_its_own_reason',
            'already_reviewed' => 'test_a_second_review_under_a_fresh_id_names_its_own_reason',
            'id_already_used' => 'test_a_reused_id_naming_a_different_review_says_so',
        ];

        $emitted = array_map(
            static fn (RefusalReason $reason): string => $reason->value,
            RefusalReason::cases(),
        );
        $named = array_keys($proven);
        sort($emitted);
        sort($named);

        self::assertSame($emitted, $named);

        foreach ($proven as $reason => $method) {
            self::assertTrue(method_exists($this, $method), "$reason: $method");
        }
    }

    public function test_a_refusal_leaks_no_internal_detail(): void
    {
        [$driver, $passenger] = $this->completedJourney(accept: false);

        $response = $this->review($passenger, rating: 5)->assertStatus(409);
        $body = (string) $response->getContent();

        foreach (['App\\', 'Illuminate', 'SQLSTATE', 'select ', 'pgsql'] as $absent) {
            self::assertStringNotContainsString($absent, $body);
        }

        $error = $response->json('error');
        self::assertIsArray($error);
        self::assertSame(['code', 'message', 'details', 'request_id'], array_keys($error));
        self::assertSame(
            $response->headers->get('X-Request-Id'),
            $error['request_id'],
        );
    }

    // --------------------------------------------------------- reading them

    public function test_reading_requires_a_credential(): void
    {
        $this->getJson('/api/v1/me/reviews')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    /**
     * CARRIES WEIGHT. A review nobody has answered is not in the list.
     */
    public function test_an_unmatched_review_is_absent_from_the_subject_feed(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->review($passenger, rating: 5)->assertStatus(201);

        $response = $this->getJson('/api/v1/me/reviews', $driver);

        $response->assertStatus(200)
            ->assertJsonPath('reviews', [])
            ->assertJsonPath('next_cursor', null);

        $this->assertMatchesOperation($response, '/api/v1/me/reviews');
    }

    public function test_a_counterpart_releases_both_sides(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->review($passenger, rating: 5)->assertStatus(201);
        $this->review($driver, rating: 4, reviewTail: 2)->assertStatus(201);

        $toDriver = $this->getJson('/api/v1/me/reviews', $driver)->assertStatus(200);
        $toPassenger = $this->getJson('/api/v1/me/reviews', $passenger)->assertStatus(200);

        $toDriver->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.rating', 5)
            ->assertJsonPath('reviews.0.reviewer.role', 'passenger')
            ->assertJsonPath('reviews.0.reviewer.display_name', 'Ayşe Demir');

        $toPassenger->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.rating', 4)
            ->assertJsonPath('reviews.0.reviewer.role', 'driver');
    }

    /**
     * CARRIES WEIGHT. Exactly these keys, and no identifier of any kind.
     */
    public function test_a_released_review_is_exactly_the_documented_shape(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->review($passenger, rating: 5)->assertStatus(201);
        $this->review($driver, rating: 4, reviewTail: 2)->assertStatus(201);

        $body = $this->getJson('/api/v1/me/reviews', $driver)->assertStatus(200)->json();
        self::assertIsArray($body);

        self::assertSame(['reviews', 'next_cursor'], array_keys($body));
        self::assertIsArray($body['reviews'][0]);
        self::assertSame(
            ['id', 'rating', 'submitted_at', 'reviewer', 'journey'],
            array_keys($body['reviews'][0]),
        );
        self::assertSame(
            ['display_name', 'initials', 'role'],
            array_keys($body['reviews'][0]['reviewer']),
        );
        self::assertSame(
            ['origin', 'destination', 'departure_date', 'departure_time'],
            array_keys($body['reviews'][0]['journey']),
        );
        self::assertSame(
            'Kadıköy, Vapur İskelesi',
            $body['reviews'][0]['journey']['origin'],
        );
        // `HH:MM`, the one time format this contract has.
        self::assertSame(5, strlen((string) $body['reviews'][0]['journey']['departure_time']));

        $this->assertNothingForbidden($body);
    }

    public function test_a_member_reads_neither_their_own_nor_a_strangers(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->review($passenger, rating: 5)->assertStatus(201);
        $this->review($driver, rating: 4, reviewTail: 2)->assertStatus(201);
        $stranger = $this->member('+905329990000', 'Deniz Kaya');

        // The passenger wrote a 5 and received a 4. Only the 4 is theirs to
        // read, so their own rating never comes back as something said to them.
        $this->getJson('/api/v1/me/reviews', $passenger)
            ->assertStatus(200)
            ->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.rating', 4);

        $this->getJson('/api/v1/me/reviews', $stranger)
            ->assertStatus(200)
            ->assertJsonPath('reviews', []);
    }

    public function test_a_cursor_from_another_feed_is_refused(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        $mine = $this->getJson('/api/v1/me/seat-requests?limit=1', $passenger)
            ->assertStatus(200)
            ->json('next_cursor');

        $this->getJson('/api/v1/me/reviews?cursor='.urlencode((string) ($mine ?? 'x')), $driver)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    // ------------------------------------------------------------ my_review

    public function test_my_review_is_null_before_the_caller_writes_one(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        $this->getJson('/api/v1/me/seat-requests', $passenger)
            ->assertStatus(200)
            ->assertJsonPath('seat_requests.0.my_review', null);

        $theirs = $this->getJson(
            '/api/v1/routes/'.$this->routeId(1).'/seat-requests',
            $driver,
        )->assertStatus(200);

        self::assertNull($this->rowOn($theirs, $this->requestId(1))['my_review']);
    }

    /**
     * CARRIES WEIGHT. Each side sees its own review and never the other's.
     */
    public function test_each_listing_carries_only_the_callers_own_review(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->review($passenger, rating: 5)->assertStatus(201);
        $this->review($driver, rating: 2, reviewTail: 2)->assertStatus(201);

        $mine = $this->getJson('/api/v1/me/seat-requests', $passenger)->assertStatus(200);
        $theirs = $this->getJson(
            '/api/v1/routes/'.$this->routeId(1).'/seat-requests',
            $driver,
        )->assertStatus(200);

        self::assertSame(5, $this->reviewOn($mine, $this->requestId(1))['rating']);
        self::assertSame(2, $this->reviewOn($theirs, $this->requestId(1))['rating']);

        self::assertSame(
            ['id', 'rating', 'submitted_at'],
            array_keys($this->reviewOn($mine, $this->requestId(1))),
        );

        // The driver's other asking is somebody else's and unreviewed, and its
        // key is present and null rather than missing.
        self::assertNull($this->rowOn($theirs, $this->requestId(2))['my_review']);
    }

    /**
     * CARRIES WEIGHT. `my_review` says nothing when only the other side wrote.
     */
    public function test_my_review_is_silent_about_the_counterpart(): void
    {
        [$driver, $passenger] = $this->completedJourney();
        $this->review($passenger, rating: 5)->assertStatus(201);

        // The driver has written nothing. Their listing must look exactly as
        // it would if the passenger had written nothing either.
        $theirs = $this->getJson(
            '/api/v1/routes/'.$this->routeId(1).'/seat-requests',
            $driver,
        )->assertStatus(200);

        self::assertNull($this->rowOn($theirs, $this->requestId(1))['my_review']);
    }

    /**
     * CARRIES WEIGHT. The Phase 14 mistake, wearing a different field name.
     *
     * `my_review` belongs to two listings. The four command responses that
     * share their payload's `from()` must not have gained it, and neither must
     * any route surface.
     */
    public function test_my_review_reaches_no_other_surface(): void
    {
        [$driver, $passenger] = $this->completedJourney();

        $surfaces = [
            'withdraw' => $this->postJson(
                '/api/v1/seat-requests/'.$this->requestId(2).'/withdraw',
                [],
                $this->member('+905323330000', 'Bora Çelik'),
            ),
            'my routes' => $this->getJson('/api/v1/me/routes', $driver),
            'discovery' => $this->getJson(
                '/api/v1/routes/discover?origin_place_id='.$this->place('kadikoy-iskele')->id
                .'&destination_place_id='.$this->place('levent-metro')->id,
                $passenger,
            ),
        ];

        foreach ($surfaces as $name => $response) {
            self::assertStringNotContainsString(
                'my_review',
                (string) $response->getContent(),
                "$name gained my_review",
            );
        }
    }

    // ------------------------------------------------------------- fixtures

    /**
     * One row of a seat-request listing, by id.
     *
     * A listing holds several askings and their order is the server's, so
     * indexing into position zero would be asserting about whichever row
     * happened to come first.
     *
     * @param  TestResponse<JsonResponse>  $response
     * @return array<string, mixed>
     */
    private function rowOn(TestResponse $response, string $requestId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json('seat_requests');

        foreach ($rows as $row) {
            if ($row['id'] === $requestId) {
                return $row;
            }
        }

        self::fail("no asking $requestId in the listing");
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return array<string, mixed>
     */
    private function reviewOn(TestResponse $response, string $requestId): array
    {
        $mine = $this->rowOn($response, $requestId)['my_review'];
        self::assertIsArray($mine, "no review on $requestId");

        return $mine;
    }

    private function assertNothingForbidden(mixed $body): void
    {
        $encoded = (string) json_encode($body);

        foreach ([
            'account_id', 'profile_id', 'phone', 'seat_request_id', 'route_id',
            'trip_id', 'counterpart', 'release_at', 'released_at',
            'review_count', 'average_rating', 'reviewer_role',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    private function assertRefused(TestResponse $response, RefusalReason $reason): void
    {
        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict')
            ->assertJsonPath('error.details.reason', $reason->value);

        $this->assertMatchesOperation(
            $response,
            '/api/v1/seat-requests/{requestId}/review',
            'post',
        );
    }

    /**
     * @param  array<string, string>  $reviewer
     * @return TestResponse<JsonResponse>
     */
    private function review(array $reviewer, int $rating, int $reviewTail = 1): TestResponse
    {
        return $this->postJson(
            '/api/v1/seat-requests/'.$this->requestId(1).'/review',
            ['id' => $this->reviewId($reviewTail), 'rating' => $rating],
            $reviewer,
        );
    }

    /**
     * A driver and a passenger, with a journey behind them.
     *
     * Two members ask: the first is accepted and is the relationship under
     * test, the second stays pending so the withdraw surface has something to
     * answer.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function completedJourney(bool $accept = true, bool $complete = true): array
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $passenger = $this->member('+905322220000', 'Ayşe Demir');

        $this->postJson('/api/v1/routes', $this->publication(), $driver)->assertStatus(201);

        $this->postJson(
            '/api/v1/routes/'.$this->routeId(1).'/seat-requests',
            ['id' => $this->requestId(1)],
            $passenger,
        )->assertStatus(201);

        // A second passenger, left pending: Phase 13 allows one request per
        // member per journey, so the withdraw surface needs somebody else's.
        $this->postJson(
            '/api/v1/routes/'.$this->routeId(1).'/seat-requests',
            ['id' => $this->requestId(2)],
            $this->member('+905323330000', 'Bora Çelik'),
        )->assertStatus(201);

        if ($accept) {
            $this->postJson(
                '/api/v1/seat-requests/'.$this->requestId(1).'/accept',
                [],
                $driver,
            )->assertStatus(200);
        }

        CarbonImmutable::setTestNow($this->departure->addMinutes(3));

        $this->postJson('/api/v1/routes/'.$this->routeId(1).'/trip/start', [], $driver)
            ->assertStatus(201);

        if ($complete) {
            $this->postJson('/api/v1/routes/'.$this->routeId(1).'/trip/complete', [], $driver)
                ->assertStatus(200);
        }

        return [$driver, $passenger];
    }

    /** Moves past the fourteen-day deadline. Credentials do not survive it. */
    private function travelPastTheWindow(): void
    {
        $completedAt = DB::table('trips')->value('completed_at');
        self::assertIsString($completedAt);

        CarbonImmutable::setTestNow(
            ReviewWindow::closesAt(CarbonImmutable::parse($completedAt))->addDay(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function member(string $phone, string $name): array
    {
        $headers = $this->bearer($this->signIn($phone)['access_token']);

        $this->putJson('/api/v1/me/profile', ['display_name' => $name], $headers)
            ->assertSuccessful();

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function publication(): array
    {
        return [
            'id' => $this->routeId(1),
            'origin_place_id' => $this->place('kadikoy-iskele')->id,
            'destination_place_id' => $this->place('levent-metro')->id,
            'recurrence' => 'once',
            'departure_date' => $this->departure->format('Y-m-d'),
            'departure_time' => $this->departure->format('H:i'),
            'seats_offered' => 3,
            'rules' => [
                'no_smoking' => true,
                'music_ok' => false,
                'no_pets' => false,
                'quiet' => false,
            ],
        ];
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    private function reviewId(int $n): string
    {
        return sprintf('01993a00-0000-7000-8000-%012d', $n);
    }

    private function requestId(int $n): string
    {
        return sprintf('01991d00-0000-7000-8000-%012d', $n);
    }

    private function routeId(int $n): string
    {
        return sprintf('01991c00-0000-7000-8000-%012d', $n);
    }
}
