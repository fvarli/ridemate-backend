<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Place;
use App\Trips\RefusalReason;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Contract\ValidatesTheContract;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * The three trip lifecycle commands over HTTP.
 *
 * The domain is already proven by StartTripTest, EndTripTest and
 * TripPersistenceTest; this is about what the wire says — which status each
 * outcome carries, which machine-readable reason a refusal names, and what a
 * response is careful not to contain.
 *
 * Every response here is also held to openapi.yaml, so a body that drifts from
 * the document fails the build rather than a review.
 */
final class TripCommandEndpointTest extends TestCase
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
        'trips',
    ];

    /**
     * The instant every fixture is measured from.
     *
     * Fixed at setUp so the departure does not drift as the test's own clock
     * moves — one recomputed after travelling would always be ahead again.
     */
    private CarbonImmutable $publishedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTestSmsSender();
        $this->seed(PilotPlaceSeeder::class);
        // In the route's own zone: the wall clock published here is read back
        // in Europe/Istanbul, so formatting it in UTC would put the departure
        // three hours into the past and publication would refuse it.
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);
        $this->publishedAt = CarbonImmutable::now($timezone);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // -------------------------------------------------------------- starting

    public function test_starting_requires_a_credential(): void
    {
        $this->postJson('/api/v1/routes/'.$this->routeId(1).'/trip/start')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_first_start_answers_201_with_the_lifecycle(): void
    {
        [$driver, $routeId] = $this->departedJourney();

        $response = $this->start($driver, $routeId);

        $response->assertStatus(201)
            ->assertJsonPath('trip.state', 'in_progress')
            ->assertJsonPath('trip.completed_at', null)
            ->assertJsonPath('trip.aborted_at', null);

        self::assertIsString($response->json('trip.started_at'));
        $this->assertMatchesOperation($response, '/api/v1/routes/{routeId}/trip/start', 'post');
    }

    /**
     * A retry is the same start, observed again.
     */
    public function test_a_repeated_start_answers_200_and_moves_nothing(): void
    {
        [$driver, $routeId] = $this->departedJourney();
        $first = $this->start($driver, $routeId)->assertStatus(201);

        // A minute later, so a command that rewrote the timestamp could not
        // pass by producing the same value.
        CarbonImmutable::setTestNow($this->departed()->addMinute());

        $again = $this->start($driver, $routeId);

        $again->assertStatus(200)
            ->assertJsonPath('trip.state', 'in_progress')
            ->assertJsonPath('trip.started_at', $first->json('trip.started_at'));

        $this->assertMatchesOperation($again, '/api/v1/routes/{routeId}/trip/start', 'post');
    }

    /**
     * CARRIES WEIGHT. Four fields, and nothing that identifies anybody.
     */
    public function test_the_response_is_exactly_the_lifecycle(): void
    {
        [$driver, $routeId] = $this->departedJourney();

        $body = $this->start($driver, $routeId)->assertStatus(201)->json();
        self::assertIsArray($body);

        self::assertSame(['trip'], array_keys($body));
        self::assertIsArray($body['trip']);
        self::assertSame(
            ['state', 'started_at', 'completed_at', 'aborted_at'],
            array_keys($body['trip']),
        );

        // No trip id, no `can_*`, no route, no passenger, and nothing from a
        // phase that has not happened. The exact key lists above already forbid
        // these; naming them is what makes the intent survive a rewrite.
        $encoded = (string) json_encode($body);
        foreach ([
            'id', 'can_', 'route', 'passenger', 'account', 'profile',
            'display_name', 'phone', 'seat', 'verification', 'trust', 'rating',
            'review', 'latitude', 'longitude', 'location', 'cost',
        ] as $absent) {
            self::assertStringNotContainsString($absent, $encoded);
        }
    }

    public function test_a_journey_that_has_not_left_yet_is_refused(): void
    {
        [$driver, $routeId] = $this->publishedJourney();

        // Deliberately no travel: the departure is two minutes out.
        $this->assertRefused(
            $this->start($driver, $routeId),
            RefusalReason::DepartureNotReached,
            'start',
        );
    }

    public function test_a_weekday_plan_cannot_be_started(): void
    {
        [$driver, $routeId] = $this->publishedJourney(recurring: true);
        $this->travelToDeparture();

        $this->assertRefused(
            $this->start($driver, $routeId),
            RefusalReason::RecurringRouteUnsupported,
            'start',
        );
    }

    /**
     * And the publication check comes before the clock.
     *
     * A withdrawn journey answers `route_unavailable` while its departure is
     * still ahead — if the clock were consulted first this would be
     * `departure_not_reached`, which would tell the driver to wait for
     * something that is never going to become possible.
     */
    public function test_a_withdrawn_journey_cannot_be_started(): void
    {
        [$driver, $routeId] = $this->publishedJourney();
        $this->postJson("/api/v1/routes/$routeId/cancel", [], $driver)->assertStatus(200);

        $this->assertRefused(
            $this->start($driver, $routeId),
            RefusalReason::RouteUnavailable,
            'start',
        );
    }

    public function test_a_finished_journey_cannot_be_started_again(): void
    {
        [$driver, $routeId] = $this->runningJourney();
        $this->postJson("/api/v1/routes/$routeId/trip/complete", [], $driver)->assertStatus(200);

        $this->assertRefused(
            $this->start($driver, $routeId),
            RefusalReason::AlreadyCompleted,
            'start',
        );
    }

    public function test_an_abandoned_journey_cannot_be_started_again(): void
    {
        [$driver, $routeId] = $this->runningJourney();
        $this->postJson("/api/v1/routes/$routeId/trip/abort", [], $driver)->assertStatus(200);

        $this->assertRefused(
            $this->start($driver, $routeId),
            RefusalReason::AlreadyAborted,
            'start',
        );
    }

    // ------------------------------------------------------------ completing

    public function test_completing_requires_a_credential(): void
    {
        $this->postJson('/api/v1/routes/'.$this->routeId(1).'/trip/complete')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_completing_a_running_journey_answers_200(): void
    {
        [$driver, $routeId] = $this->runningJourney();

        $response = $this->postJson("/api/v1/routes/$routeId/trip/complete", [], $driver);

        $response->assertStatus(200)
            ->assertJsonPath('trip.state', 'completed')
            ->assertJsonPath('trip.aborted_at', null);

        self::assertIsString($response->json('trip.started_at'));
        self::assertIsString($response->json('trip.completed_at'));
        $this->assertMatchesOperation($response, '/api/v1/routes/{routeId}/trip/complete', 'post');
    }

    public function test_completing_twice_leaves_the_ending_where_it_was(): void
    {
        [$driver, $routeId] = $this->runningJourney();
        $first = $this->postJson("/api/v1/routes/$routeId/trip/complete", [], $driver)
            ->assertStatus(200);

        CarbonImmutable::setTestNow($this->departed()->addMinute());

        $this->postJson("/api/v1/routes/$routeId/trip/complete", [], $driver)
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'completed')
            ->assertJsonPath('trip.completed_at', $first->json('trip.completed_at'));
    }

    public function test_completing_a_journey_nobody_started_is_refused(): void
    {
        [$driver, $routeId] = $this->departedJourney();

        $this->assertRefused(
            $this->postJson("/api/v1/routes/$routeId/trip/complete", [], $driver),
            RefusalReason::TripNotStarted,
            'complete',
        );
    }

    public function test_an_abandoned_journey_cannot_be_completed(): void
    {
        [$driver, $routeId] = $this->runningJourney();
        $this->postJson("/api/v1/routes/$routeId/trip/abort", [], $driver)->assertStatus(200);

        $this->assertRefused(
            $this->postJson("/api/v1/routes/$routeId/trip/complete", [], $driver),
            RefusalReason::AlreadyAborted,
            'complete',
        );
    }

    // ------------------------------------------------------------ abandoning

    public function test_abandoning_requires_a_credential(): void
    {
        $this->postJson('/api/v1/routes/'.$this->routeId(1).'/trip/abort')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_abandoning_a_running_journey_answers_200(): void
    {
        [$driver, $routeId] = $this->runningJourney();

        $response = $this->postJson("/api/v1/routes/$routeId/trip/abort", [], $driver);

        $response->assertStatus(200)
            ->assertJsonPath('trip.state', 'aborted')
            ->assertJsonPath('trip.completed_at', null);

        self::assertIsString($response->json('trip.aborted_at'));
        $this->assertMatchesOperation($response, '/api/v1/routes/{routeId}/trip/abort', 'post');
    }

    public function test_abandoning_twice_leaves_the_ending_where_it_was(): void
    {
        [$driver, $routeId] = $this->runningJourney();
        $first = $this->postJson("/api/v1/routes/$routeId/trip/abort", [], $driver)
            ->assertStatus(200);

        CarbonImmutable::setTestNow($this->departed()->addMinute());

        $this->postJson("/api/v1/routes/$routeId/trip/abort", [], $driver)
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'aborted')
            ->assertJsonPath('trip.aborted_at', $first->json('trip.aborted_at'));
    }

    public function test_abandoning_a_journey_nobody_started_is_refused(): void
    {
        [$driver, $routeId] = $this->departedJourney();

        $this->assertRefused(
            $this->postJson("/api/v1/routes/$routeId/trip/abort", [], $driver),
            RefusalReason::TripNotStarted,
            'abort',
        );
    }

    public function test_a_finished_journey_cannot_be_abandoned(): void
    {
        [$driver, $routeId] = $this->runningJourney();
        $this->postJson("/api/v1/routes/$routeId/trip/complete", [], $driver)->assertStatus(200);

        $this->assertRefused(
            $this->postJson("/api/v1/routes/$routeId/trip/abort", [], $driver),
            RefusalReason::AlreadyCompleted,
            'abort',
        );
    }

    // -------------------------------------------------------- whose journey

    /**
     * Both answers are the same 404, so neither can be told from the other.
     */
    public function test_a_journey_that_is_not_the_callers_is_an_undistinguished_404(): void
    {
        [, $routeId] = $this->runningJourney();
        $stranger = $this->member('+905322220000', 'Ayşe Demir');

        foreach (['start', 'complete', 'abort'] as $command) {
            $mine = $this->postJson("/api/v1/routes/$routeId/trip/$command", [], $stranger);
            $nobodys = $this->postJson(
                '/api/v1/routes/'.$this->routeId(9).'/trip/'.$command,
                [],
                $stranger,
            );

            foreach ([$mine, $nobodys] as $response) {
                $response->assertStatus(404)
                    ->assertJsonPath('error.code', 'not_found')
                    ->assertJsonPath('error.message', 'The requested resource was not found.')
                    ->assertJsonMissingPath('error.details');

                $this->assertMatchesOperation(
                    $response,
                    "/api/v1/routes/{routeId}/trip/$command",
                    'post',
                );
            }
        }
    }

    /**
     * A malformed id never matches the route at all.
     *
     * It is an ordinary 404 rather than a 422 the contract does not describe —
     * and the same answer as an id that is real but somebody else's.
     */
    public function test_an_id_that_is_not_a_uuid_v7_is_the_same_404(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');

        $this->postJson('/api/v1/routes/not-a-uuid/trip/start', [], $driver)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    // ------------------------------------------------------- the error shape

    /**
     * CARRIES WEIGHT. Every reason the domain owns is reached by a test here.
     *
     * A refusal vocabulary is only contract if each string can actually be
     * produced; one that nothing emits is documentation of an intention.
     *
     * Declared rather than accumulated across tests. A counter filled by the
     * other methods would depend on all of them having run, so it would pass
     * for the wrong reason under `--filter` and fail for the wrong reason under
     * a reordering. This says which test proves which reason, and checks the
     * mapping covers the enum exactly and that each named test exists — a
     * seventh reason with nowhere to be produced fails here.
     *
     * @var array<string, string>
     */
    private const PROVEN_BY = [
        'recurring_route_unsupported' => 'test_a_weekday_plan_cannot_be_started',
        'departure_not_reached' => 'test_a_journey_that_has_not_left_yet_is_refused',
        'route_unavailable' => 'test_a_withdrawn_journey_cannot_be_started',
        'trip_not_started' => 'test_completing_a_journey_nobody_started_is_refused',
        'already_completed' => 'test_a_finished_journey_cannot_be_started_again',
        'already_aborted' => 'test_an_abandoned_journey_cannot_be_started_again',
    ];

    public function test_every_documented_reason_is_reachable_over_http(): void
    {
        $emitted = array_map(
            static fn (RefusalReason $reason): string => $reason->value,
            RefusalReason::cases(),
        );

        $proven = array_keys(self::PROVEN_BY);
        sort($emitted);
        sort($proven);

        self::assertSame(
            $emitted,
            $proven,
            'a trip refusal reason has no endpoint test that produces it',
        );

        foreach (self::PROVEN_BY as $reason => $method) {
            self::assertTrue(
                method_exists($this, $method),
                "$reason names a test that does not exist: $method",
            );
        }
    }

    /**
     * A refusal describes the state and nothing about the machine.
     */
    public function test_a_refusal_leaks_no_internal_detail(): void
    {
        [$driver, $routeId] = $this->departedJourney();

        $response = $this->postJson("/api/v1/routes/$routeId/trip/complete", [], $driver)
            ->assertStatus(409);

        $body = (string) $response->getContent();

        foreach (['App\\', 'Illuminate', 'Trip::class', 'SQLSTATE', 'select ', 'pgsql', $routeId] as $absent) {
            self::assertStringNotContainsString($absent, $body);
        }

        // The envelope is the shared one, complete and in order.
        $error = $response->json('error');
        self::assertIsArray($error);
        self::assertSame(['code', 'message', 'details', 'request_id'], array_keys($error));
        self::assertSame(
            $response->headers->get('X-Request-Id'),
            $error['request_id'],
            'the body should carry the same request id as the header',
        );
    }

    /**
     * A body is neither required nor read.
     *
     * These commands name a target state, so there is nothing for a caller to
     * supply. Sending fields anyway must not become semantics — no reason is
     * stored, no timestamp is accepted, and nothing is echoed back.
     */
    public function test_unexpected_fields_introduce_nothing(): void
    {
        [$driver, $routeId] = $this->runningJourney();

        $response = $this->postJson("/api/v1/routes/$routeId/trip/abort", [
            'reason' => 'passenger never showed up',
            'aborted_at' => '2001-01-01T00:00:00Z',
            'id' => '01991c00-0000-7000-8000-000000000099',
            'expected_status' => 'in_progress',
        ], $driver);

        $response->assertStatus(200)->assertJsonPath('trip.state', 'aborted');

        $body = $response->json();
        self::assertIsArray($body);
        self::assertIsArray($body['trip']);
        self::assertSame(
            ['state', 'started_at', 'completed_at', 'aborted_at'],
            array_keys($body['trip']),
        );
        self::assertNotSame('2001-01-01T00:00:00Z', $body['trip']['aborted_at']);
        self::assertStringNotContainsString('showed up', (string) json_encode($body));
    }

    // ------------------------------------------------------------- fixtures

    /**
     * A refusal: the status, the code, the reason, and the document.
     *
     * @param  TestResponse<JsonResponse>  $response
     */
    private function assertRefused(
        TestResponse $response,
        RefusalReason $reason,
        string $command,
    ): void {
        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict')
            ->assertJsonPath('error.details.reason', $reason->value)
            // Seat requests carry this; a trip refusal does not, because the
            // caller owns the journey and can read its lifecycle from My Routes.
            ->assertJsonMissingPath('error.details.current_status');

        $this->assertMatchesOperation($response, "/api/v1/routes/{routeId}/trip/$command", 'post');
    }

    /**
     * @param  array<string, string>  $driver
     * @return TestResponse<JsonResponse>
     */
    private function start(array $driver, string $routeId): TestResponse
    {
        return $this->postJson("/api/v1/routes/$routeId/trip/start", [], $driver);
    }

    /**
     * A published one-off journey, still ahead of its departure.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function publishedJourney(bool $recurring = false): array
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');

        $body = [
            'id' => $this->routeId(1),
            'origin_place_id' => $this->place('kadikoy-iskele')->id,
            'destination_place_id' => $this->place('levent-metro')->id,
            'recurrence' => $recurring ? 'weekdays' : 'once',
            // Absent for a recurring plan rather than null: a weekday commute
            // has no single date, and publication refuses the key outright.
            ...($recurring ? [] : ['departure_date' => $this->departure()->format('Y-m-d')]),
            'departure_time' => $this->departure()->format('H:i'),
            'seats_offered' => 3,
            'rules' => [
                'no_smoking' => true,
                'music_ok' => false,
                'no_pets' => false,
                'quiet' => false,
            ],
        ];

        $this->postJson('/api/v1/routes', $body, $driver)->assertStatus(201);

        return [$driver, $this->routeId(1)];
    }

    /**
     * The same journey, with the clock now past its departure.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function departedJourney(): array
    {
        $journey = $this->publishedJourney();
        $this->travelToDeparture();

        return $journey;
    }

    /**
     * And started, through the endpoint this file is about.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function runningJourney(): array
    {
        [$driver, $routeId] = $this->departedJourney();
        $this->start($driver, $routeId)->assertStatus(201);

        return [$driver, $routeId];
    }

    /**
     * Moves the app clock past the fixture departure.
     *
     * The route's own timezone decides when it has left, so the command's clock
     * and the response's must be the same one — an instant injected into the
     * command alone would leave the rest of the request in the real present.
     */
    private function travelToDeparture(): void
    {
        CarbonImmutable::setTestNow($this->departed());
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

    /** When the fixture journey is due. */
    private function departure(): CarbonImmutable
    {
        // Two minutes out, so travelling past it stays inside the access
        // token's fifteen-minute life.
        return $this->publishedAt->addMinutes(2);
    }

    /** A moment after it was due. */
    private function departed(): CarbonImmutable
    {
        return $this->departure()->addMinutes(3);
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    private function routeId(int $n): string
    {
        return sprintf('01991c00-0000-7000-8000-%012d', $n);
    }
}
