<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Place;
use App\SeatRequests\ListMySeatRequests;
use App\SeatRequests\ListRouteSeatRequests;
use App\Support\KeysetCursor;
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
 * Seat requests over HTTP.
 *
 * The domain is already proven; this is about what the wire says — which status
 * each outcome carries, which machine-readable reason a refusal names, and what
 * a response is careful not to contain.
 */
final class SeatRequestEndpointTest extends TestCase
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
        'routes',
        'seat_requests',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTestSmsSender();
        $this->seed(PilotPlaceSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // -------------------------------------------------------------- asking

    public function test_asking_requires_a_credential(): void
    {
        $this->postJson('/api/v1/routes/'.$this->routeId(1).'/seat-requests', ['id' => $this->id(1)])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_first_asking_answers_201(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        $response = $this->postJson(
            "/api/v1/routes/$routeId/seat-requests",
            ['id' => $this->id(1)],
            $passenger,
        );

        $response->assertStatus(201)
            ->assertJsonPath('seat_request.id', $this->id(1))
            ->assertJsonPath('seat_request.status', 'pending')
            ->assertJsonPath('seat_request.decided_at', null)
            ->assertJsonPath('seat_request.withdrawn_at', null)
            ->assertJsonPath('seat_request.route.id', $routeId)
            ->assertJsonPath('seat_request.route.status', 'published')
            ->assertJsonPath('seat_request.route.departure_state', 'upcoming')
            ->assertJsonPath('seat_request.route.driver.display_name', 'İrem Yılmaz')
            ->assertJsonPath('seat_request.route.driver.initials', 'İY');

        $this->assertMatchesOperation(
            $response,
            '/api/v1/routes/{routeId}/seat-requests',
            'post',
        );
    }

    /** The same id is the same asking, and answers 200 the second time. */
    public function test_a_retry_answers_200_with_the_same_request(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        $this->ask($passenger, $routeId, 1)->assertStatus(201);

        $this->ask($passenger, $routeId, 1)
            ->assertStatus(200)
            ->assertJsonPath('seat_request.id', $this->id(1));
    }

    /**
     * CARRIES WEIGHT. Everything a response must never contain.
     *
     * Encoded as a scan of the whole body rather than a list of absent keys,
     * because a nested one added later would slip past the narrower check.
     */
    public function test_a_response_carries_no_identifiers_and_no_unearned_claims(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        $body = $this->ask($passenger, $routeId, 1)->assertStatus(201)->content();

        foreach ([
            'account_id', 'profile_id', 'phone', 'e164', 'token', 'session',
            'rating', 'trust', 'verified', 'verification', 'approval',
            'compatibility', 'cost', 'fare', 'price', 'amount', 'payment',
            'seats_remaining', 'seats_available', 'remaining',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body);
        }
    }

    // ----------------------------------------------------------- refusals

    public function test_a_caller_without_a_profile_is_a_422_naming_the_prerequisite(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        // Verified a phone number and never chose a name.
        $nameless = $this->bearer($this->signIn('+905323330000')['access_token']);

        $this->ask($nameless, $routeId, 1)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.reason', 'profile_required')
            ->assertJsonMissingPath('error.details.current_status');
    }

    public function test_asking_for_a_seat_in_your_own_car_is_a_409(): void
    {
        [$driver, $routeId] = $this->publishedJourney();

        $this->ask($driver, $routeId, 1)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict')
            ->assertJsonPath('error.details.reason', 'own_route');
    }

    public function test_a_recurring_journey_is_a_409(): void
    {
        [$driver, $routeId] = $this->publishedJourney(recurring: true);
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        $this->ask($passenger, $routeId, 1)
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'recurring_route_unsupported');
    }

    /**
     * A cancelled journey is a 404, and says nothing more.
     *
     * A distinguishable refusal would confirm which route ids exist to anybody
     * who guessed one.
     */
    public function test_a_cancelled_journey_is_an_undistinguished_404(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $this->postJson("/api/v1/routes/$routeId/cancel", [], $driver)->assertStatus(200);
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        $this->ask($passenger, $routeId, 1)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonMissingPath('error.details');
    }

    public function test_an_unknown_journey_is_the_same_404(): void
    {
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        $this->ask($passenger, $this->routeId(99), 1)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonMissingPath('error.details');
    }

    /** Says the id is taken, and nothing about whose it is or what it points at. */
    public function test_another_members_id_is_a_409_that_leaks_nothing(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $theirs = $this->member('+905322220001', 'Ayşe Demir');
        $mine = $this->member('+905322220002', 'Zeynep Kaya');

        $this->ask($theirs, $routeId, 1)->assertStatus(201);

        $response = $this->ask($mine, $routeId, 1)
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'id_already_used')
            ->assertJsonMissingPath('error.details.current_status');

        self::assertStringNotContainsString('Ayşe', $response->content());
    }

    /** The caller's own request, so telling them where it stands is theirs to know. */
    public function test_asking_twice_under_different_ids_is_a_409_with_the_current_status(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        $this->ask($passenger, $routeId, 1)->assertStatus(201);

        $this->ask($passenger, $routeId, 2)
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'already_requested')
            ->assertJsonPath('error.details.current_status', 'pending');
    }

    // -------------------------------------------------------- transitions

    public function test_withdrawing_is_idempotent_over_http(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);

        $first = $this->postJson($this->withdraw(1), [], $passenger)
            ->assertStatus(200)
            ->assertJsonPath('seat_request.status', 'withdrawn');

        $this->postJson($this->withdraw(1), [], $passenger)
            ->assertStatus(200)
            ->assertJsonPath('seat_request.status', 'withdrawn')
            ->assertJsonPath('seat_request.withdrawn_at', $first->json('seat_request.withdrawn_at'));

        $this->assertMatchesOperation(
            $this->postJson($this->withdraw(1), [], $passenger),
            '/api/v1/seat-requests/{requestId}/withdraw',
            'post',
        );
    }

    public function test_accepting_is_idempotent_over_http(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);

        $first = $this->postJson($this->accept(1), [], $driver)
            ->assertStatus(200)
            ->assertJsonPath('seat_request.status', 'accepted')
            ->assertJsonPath('seat_request.passenger.display_name', 'Ayşe Demir')
            ->assertJsonPath('seat_request.passenger.initials', 'AD');

        $this->postJson($this->accept(1), [], $driver)
            ->assertStatus(200)
            ->assertJsonPath('seat_request.decided_at', $first->json('seat_request.decided_at'));

        $this->assertMatchesOperation(
            $this->postJson($this->accept(1), [], $driver),
            '/api/v1/seat-requests/{requestId}/accept',
            'post',
        );
    }

    public function test_declining_answers_the_driver_projection(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);

        $response = $this->postJson($this->decline(1), [], $driver)
            ->assertStatus(200)
            ->assertJsonPath('seat_request.status', 'declined');

        // The journey is in the path and owned by the caller, so it is not
        // repeated on the row.
        $response->assertJsonMissingPath('seat_request.route');

        $this->assertMatchesOperation(
            $response,
            '/api/v1/seat-requests/{requestId}/decline',
            'post',
        );
    }

    public function test_a_full_journey_refuses_a_further_acceptance(): void
    {
        [$driver, $routeId] = $this->publishedJourney(seats: 1);
        $first = $this->member('+905322220001', 'Ayşe Demir');
        $second = $this->member('+905322220002', 'Zeynep Kaya');
        $this->ask($first, $routeId, 1)->assertStatus(201);
        $this->ask($second, $routeId, 2)->assertStatus(201);

        $this->postJson($this->accept(1), [], $driver)->assertStatus(200);

        $this->postJson($this->accept(2), [], $driver)
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'route_full');
    }

    public function test_a_cancelled_journey_refuses_a_new_acceptance(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);
        $this->postJson("/api/v1/routes/$routeId/cancel", [], $driver)->assertStatus(200);

        $this->postJson($this->accept(1), [], $driver)
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'route_unavailable');
    }

    public function test_a_terminal_request_names_its_state(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);
        $this->postJson($this->accept(1), [], $driver)->assertStatus(200);

        $this->postJson($this->withdraw(1), [], $passenger)
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'already_accepted')
            ->assertJsonPath('error.details.current_status', 'accepted');
    }

    // ----------------------------------------------------------- wrong role

    public function test_a_passenger_cannot_accept_and_gets_a_404(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);

        $this->postJson($this->accept(1), [], $passenger)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_driver_cannot_withdraw_and_gets_a_404(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);

        $this->postJson($this->withdraw(1), [], $driver)
            ->assertStatus(404);
    }

    // ------------------------------------------------------------ listings

    public function test_a_member_pages_their_own_history(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        [, $second] = $this->publishedJourney(driver: $driver, n: 2);
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);
        $this->ask($passenger, $second, 2)->assertStatus(201);

        $first = $this->getJson('/api/v1/me/seat-requests?limit=1', $passenger)
            ->assertStatus(200)
            ->assertJsonCount(1, 'seat_requests')
            ->assertJsonPath('seat_requests.0.id', $this->id(2));

        $this->assertMatchesOperation($first, '/api/v1/me/seat-requests');

        $cursor = $first->json('next_cursor');
        self::assertIsString($cursor);

        $this->getJson('/api/v1/me/seat-requests?limit=1&cursor='.urlencode($cursor), $passenger)
            ->assertStatus(200)
            ->assertJsonPath('seat_requests.0.id', $this->id(1))
            ->assertJsonPath('next_cursor', null);
    }

    public function test_a_driver_pages_the_requests_on_their_journey(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $this->ask($this->member('+905322220001', 'Ayşe Demir'), $routeId, 1)->assertStatus(201);

        $response = $this->getJson("/api/v1/routes/$routeId/seat-requests", $driver)
            ->assertStatus(200)
            ->assertJsonCount(1, 'seat_requests')
            ->assertJsonPath('seat_requests.0.passenger.display_name', 'Ayşe Demir');

        $this->assertMatchesOperation($response, '/api/v1/routes/{routeId}/seat-requests');
    }

    public function test_another_driver_cannot_read_the_requests(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $this->ask($this->member('+905322220001', 'Ayşe Demir'), $routeId, 1)->assertStatus(201);
        $stranger = $this->member('+905321119999', 'Mert Kaya');

        $this->getJson("/api/v1/routes/$routeId/seat-requests", $stranger)
            ->assertStatus(404);
    }

    /** Two feeds, the same sort tuple, and cursors that cannot cross. */
    public function test_each_listing_refuses_the_others_cursor(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $instant = CarbonImmutable::now();

        $mine = (new KeysetCursor($instant, $this->id(1), ListMySeatRequests::CURSOR))->encode();
        $theirs = (new KeysetCursor($instant, $this->id(1), ListRouteSeatRequests::CURSOR))->encode();

        $this->getJson('/api/v1/me/seat-requests?cursor='.urlencode($theirs), $passenger)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->getJson(
            "/api/v1/routes/$routeId/seat-requests?cursor=".urlencode($mine),
            $driver,
        )->assertStatus(422);
    }

    // ----------------------------------------------------------- discovery

    public function test_discovery_says_null_before_the_caller_has_asked(): void
    {
        [$driver] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        $this->discover($passenger)
            ->assertStatus(200)
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.my_seat_request', null);
    }

    /**
     * THE REASON THIS FIELD EXISTS.
     *
     * Without it a reloaded card would offer to ask again on a journey the
     * server already knows cannot be asked about, and the member would only
     * find out by tapping.
     */
    public function test_discovery_carries_the_callers_own_request_after_asking(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);

        $this->discover($passenger)
            ->assertStatus(200)
            ->assertJsonPath('routes.0.my_seat_request.id', $this->id(1))
            ->assertJsonPath('routes.0.my_seat_request.status', 'pending');
    }

    public function test_a_terminal_request_still_shows_in_discovery(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->ask($passenger, $routeId, 1)->assertStatus(201);
        $this->postJson($this->withdraw(1), [], $passenger)->assertStatus(200);

        // Lifetime uniqueness means this journey can never be asked about
        // again, so the card must not offer to.
        $this->discover($passenger)
            ->assertJsonPath('routes.0.my_seat_request.status', 'withdrawn');
    }

    public function test_another_members_request_never_appears_as_the_callers(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $theirs = $this->member('+905322220001', 'Ayşe Demir');
        $mine = $this->member('+905322220002', 'Zeynep Kaya');
        $this->ask($theirs, $routeId, 1)->assertStatus(201);

        $this->discover($mine)
            ->assertStatus(200)
            ->assertJsonPath('routes.0.my_seat_request', null);
    }

    /**
     * One lookup for the page, whatever the page holds.
     *
     * Resolving per candidate would multiply discovery's fill scan by a query
     * each; resolving per returned route would be an N+1 on the page.
     */
    public function test_discovery_does_not_query_per_result(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $passenger = $this->member('+905322220001', 'Ayşe Demir');

        [, $one] = $this->publishedJourney(driver: $driver, n: 1);
        $this->ask($passenger, $one, 1)->assertStatus(201);
        $single = $this->queriesFor(fn () => $this->discover($passenger, limit: 1));

        foreach ([2, 3, 4] as $n) {
            [, $routeId] = $this->publishedJourney(driver: $driver, n: $n);
            $this->ask($passenger, $routeId, $n)->assertStatus(201);
        }

        $many = $this->queriesFor(fn () => $this->discover($passenger, limit: 4));

        self::assertSame($single, $many, 'discovery queries grew with the page');
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function ask(array $headers, string $routeId, int $n): TestResponse
    {
        return $this->postJson(
            "/api/v1/routes/$routeId/seat-requests",
            ['id' => $this->id($n)],
            $headers,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function discover(array $headers, int $limit = 20): TestResponse
    {
        return $this->getJson(sprintf(
            '/api/v1/routes/discover?origin_place_id=%s&destination_place_id=%s&limit=%d',
            $this->place('kadikoy-iskele')->id,
            $this->place('levent-metro')->id,
            $limit,
        ), $headers);
    }

    /**
     * A signed-in member who has chosen a display name.
     *
     * @return array<string, string>
     */
    private function member(string $phone, string $name): array
    {
        // signIn returns the token pair; the bearer header is built from it.
        $headers = $this->bearer($this->signIn($phone)['access_token']);

        // 201 the first time a member names themselves, 200 on a later change.
        $this->putJson('/api/v1/me/profile', ['display_name' => $name], $headers)
            ->assertSuccessful();

        return $headers;
    }

    /**
     * @param  array<string, string>|null  $driver
     * @return array{0: array<string, string>, 1: string}
     */
    private function publishedJourney(
        ?array $driver = null,
        int $n = 1,
        bool $recurring = false,
        int $seats = 3,
    ): array {
        $driver ??= $this->member('+905321110000', 'İrem Yılmaz');

        $body = [
            'id' => $this->routeId($n),
            'origin_place_id' => $this->place('kadikoy-iskele')->id,
            'destination_place_id' => $this->place('levent-metro')->id,
            'recurrence' => $recurring ? 'weekdays' : 'once',
            // Absent for a recurring plan rather than null: a weekday commute
            // has no single date, and publication refuses the key outright.
            ...($recurring
                ? []
                : ['departure_date' => CarbonImmutable::now()->addDays(3)->format('Y-m-d')]),
            'departure_time' => '08:25',
            'seats_offered' => $seats,
            'rules' => [
                'no_smoking' => true,
                'music_ok' => false,
                'no_pets' => false,
                'quiet' => false,
            ],
        ];

        $this->postJson('/api/v1/routes', $body, $driver)->assertStatus(201);

        return [$driver, $this->routeId($n)];
    }

    private function queriesFor(callable $read): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    private function withdraw(int $n): string
    {
        return '/api/v1/seat-requests/'.$this->id($n).'/withdraw';
    }

    private function accept(int $n): string
    {
        return '/api/v1/seat-requests/'.$this->id($n).'/accept';
    }

    private function decline(int $n): string
    {
        return '/api/v1/seat-requests/'.$this->id($n).'/decline';
    }

    private function id(int $n): string
    {
        return sprintf('01991d00-0000-7000-8000-%012d', $n);
    }

    private function routeId(int $n): string
    {
        return sprintf('01991c00-0000-7000-8000-%012d', $n);
    }
}
