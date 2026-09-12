<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Trips\AbortTrip;
use App\Trips\CompleteTrip;
use App\Trips\StartTrip;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Contract\ValidatesTheContract;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * Whether a journey was made, on the two surfaces that may say so.
 *
 * Both are somebody's own: the driver's published journeys and the passenger's
 * own askings. The public feed is deliberately not one of them, and a guard
 * here says so rather than leaving it to the fact that the payload classes
 * happen to be separate.
 *
 * The other thing this file exists for is that a page must not start costing a
 * query per journey — the lesson `my_seat_request` taught in Phase 12.
 */
final class TripLifecycleProjectionTest extends TestCase
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
     * Fixed at setUp so `departure()` and `departed()` do not drift as the
     * test's own clock moves — a departure recomputed after travelling would
     * always be two minutes in the future.
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

    /**
     * Moves the app clock past the fixture departure.
     *
     * Start requires the departure instant to have been reached, so in
     * production a running journey is always `past`. Injecting the instant into
     * the command alone would leave the response computing `departure_state`
     * against the real clock and produce a combination that cannot occur.
     */
    private function travelToDeparture(): CarbonImmutable
    {
        CarbonImmutable::setTestNow($this->departed());

        return $this->departed();
    }

    // ---------------------------------------------------------- My Routes

    public function test_a_journey_nobody_started_says_not_started(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $this->publish($driver, 1);

        $trip = $this->myRoutes($driver)['routes'][0]['trip'];

        self::assertSame('not_started', $trip['state']);
        self::assertNull($trip['started_at']);
        self::assertNull($trip['completed_at']);
        self::assertNull($trip['aborted_at']);
    }

    public function test_a_running_journey_carries_only_its_start(): void
    {
        [$driver, $routeId] = $this->startedJourney();

        $trip = $this->myRoutes($driver)['routes'][0]['trip'];

        self::assertSame('in_progress', $trip['state']);
        self::assertNotNull($trip['started_at']);
        self::assertNull($trip['completed_at']);
        self::assertNull($trip['aborted_at']);
        self::assertSame($routeId, $this->myRoutes($driver)['routes'][0]['id']);
    }

    public function test_a_completed_journey_carries_its_ending(): void
    {
        [$driver, $routeId] = $this->startedJourney();
        app(CompleteTrip::class)($this->account($driver), $routeId, $this->departed()->addHour());

        $trip = $this->myRoutes($driver)['routes'][0]['trip'];

        self::assertSame('completed', $trip['state']);
        self::assertNotNull($trip['started_at']);
        self::assertNotNull($trip['completed_at']);
        self::assertNull($trip['aborted_at']);
    }

    public function test_an_abandoned_journey_carries_its_ending(): void
    {
        [$driver, $routeId] = $this->startedJourney();
        app(AbortTrip::class)($this->account($driver), $routeId, $this->departed()->addHour());

        $trip = $this->myRoutes($driver)['routes'][0]['trip'];

        self::assertSame('aborted', $trip['state']);
        self::assertNotNull($trip['started_at']);
        self::assertNull($trip['completed_at']);
        self::assertNotNull($trip['aborted_at']);
    }

    /**
     * CARRIES WEIGHT. An exact key set, so a new field reaches anybody only
     * when somebody edits this.
     */
    public function test_the_trip_object_carries_exactly_four_keys_and_no_id(): void
    {
        [$driver] = $this->startedJourney();

        $trip = $this->myRoutes($driver)['routes'][0]['trip'];

        self::assertSame(
            ['state', 'started_at', 'completed_at', 'aborted_at'],
            array_keys($trip),
        );
        // No identifier and no policy: a route has one trip, the endpoints are
        // route-scoped, and whether a control should be offered is the
        // client's decision from this truth.
        foreach (['id', 'can_start', 'can_complete', 'can_abort', 'can_review', 'is_active'] as $absent) {
            self::assertArrayNotHasKey($absent, $trip);
        }
    }

    public function test_the_rest_of_the_route_payload_is_unchanged(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $this->publish($driver, 1);

        self::assertSame([
            'id', 'origin', 'destination', 'recurrence', 'departure_date',
            'departure_time', 'timezone', 'departure_state', 'seats_offered',
            'rules', 'status', 'published_at', 'cancelled_at', 'trip',
        ], array_keys($this->myRoutes($driver)['routes'][0]));
    }

    // -------------------------------------------------- My Seat Requests

    public function test_a_passenger_sees_the_journeys_lifecycle(): void
    {
        [$driver, $routeId, $passenger] = $this->askedAndAccepted();

        $request = $this->mySeatRequests($passenger)['seat_requests'][0];

        self::assertSame('accepted', $request['status']);
        self::assertSame('published', $request['route']['status']);
        self::assertSame('not_started', $request['route']['trip']['state']);

        app(StartTrip::class)($this->account($driver), $routeId, $this->travelToDeparture());

        $running = $this->mySeatRequests($passenger)['seat_requests'][0];

        // Four independent facts, and only one of them moved.
        self::assertSame('accepted', $running['status']);
        self::assertSame('published', $running['route']['status']);
        self::assertSame('past', $running['route']['departure_state']);
        self::assertSame('in_progress', $running['route']['trip']['state']);
    }

    /**
     * THE COMBINATION THE CONTRACT INSISTS ON.
     *
     * A seat the driver agreed to give, on a journey that was made. The asking
     * is not rewritten by the journey ending — no `completed` request status
     * exists, and none is invented.
     */
    public function test_an_accepted_request_survives_the_journey_being_completed(): void
    {
        [$driver, $routeId, $passenger] = $this->askedAndAccepted();
        app(StartTrip::class)($this->account($driver), $routeId, $this->travelToDeparture());
        app(CompleteTrip::class)($this->account($driver), $routeId, $this->departed()->addHour());

        $request = $this->mySeatRequests($passenger)['seat_requests'][0];

        self::assertSame('accepted', $request['status']);
        self::assertSame('completed', $request['route']['trip']['state']);
        self::assertSame('published', $request['route']['status']);
    }

    public function test_a_cancelled_journey_that_never_ran_says_not_started(): void
    {
        [$driver, $routeId, $passenger] = $this->askedAndAccepted();
        $this->postJson("/api/v1/routes/$routeId/cancel", [], $driver)->assertStatus(200);

        $request = $this->mySeatRequests($passenger)['seat_requests'][0];

        self::assertSame('accepted', $request['status']);
        self::assertSame('cancelled', $request['route']['status']);
        self::assertSame('not_started', $request['route']['trip']['state']);
    }

    public function test_a_pending_request_can_sit_on_a_running_journey(): void
    {
        [$driver, $routeId, $passenger] = $this->askedAndAccepted(accept: false);
        app(StartTrip::class)($this->account($driver), $routeId, $this->travelToDeparture());

        $request = $this->mySeatRequests($passenger)['seat_requests'][0];

        self::assertSame('pending', $request['status']);
        self::assertSame('in_progress', $request['route']['trip']['state']);
    }

    public function test_the_passengers_trip_object_carries_no_id_either(): void
    {
        [$driver, $routeId, $passenger] = $this->askedAndAccepted();
        app(StartTrip::class)($this->account($driver), $routeId, $this->travelToDeparture());

        $trip = $this->mySeatRequests($passenger)['seat_requests'][0]['route']['trip'];

        self::assertSame(
            ['state', 'started_at', 'completed_at', 'aborted_at'],
            array_keys($trip),
        );
    }

    // ------------------------------------------------------ scope guard

    /**
     * CARRIES WEIGHT. A stranger learns nothing about whether a journey ran.
     *
     * Discovery is the one seat-request surface a member does not own, and
     * whether somebody's car is currently moving is not a fact the public feed
     * has any business carrying.
     */
    public function test_discovery_gains_no_trip_field(): void
    {
        // An UPCOMING journey, deliberately not started: discovery only shows
        // upcoming routes, so a running one is absent from the feed entirely
        // and an empty page would prove nothing about the key. This asserts a
        // result is actually present and still carries no lifecycle.
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $this->publish($driver, 1);
        $searcher = $this->member('+905322220001', 'Ayşe Demir');

        $body = $this->getJson(sprintf(
            '/api/v1/routes/discover?origin_place_id=%s&destination_place_id=%s',
            $this->place('kadikoy-iskele')->id,
            $this->place('levent-metro')->id,
        ), $searcher)->assertStatus(200)->json();

        self::assertIsArray($body['routes']);
        self::assertCount(1, $body['routes'], 'the guard needs a result to guard');

        foreach ($body['routes'] as $route) {
            self::assertArrayNotHasKey('trip', $route);
        }

        // And nothing anywhere in that body mentions one.
        self::assertStringNotContainsString(
            'trip',
            (string) json_encode($body),
        );
    }

    /**
     * CARRIES WEIGHT. Publishing answers with the plain route, as it always did.
     *
     * These two endpoints are owner-only, which is exactly why the mistake was
     * easy: they share `RoutePayload` with My Routes, so adding a field there
     * widened three surfaces to serve two. Owner-only is not the boundary — the
     * locked surface list is.
     */
    public function test_publishing_a_journey_answers_without_a_lifecycle(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');

        $response = $this->postJson('/api/v1/routes', $this->publication(1), $driver)
            ->assertStatus(201);

        $this->assertMatchesOperation($response, '/api/v1/routes', 'post');

        /** @var array{route: array<string, mixed>} $body */
        $body = $response->json();

        self::assertArrayNotHasKey('trip', $body['route']);
        self::assertStringNotContainsString('trip', (string) json_encode($body));
    }

    public function test_cancelling_a_journey_answers_without_a_lifecycle(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $this->publish($driver, 1);

        $response = $this->postJson(
            '/api/v1/routes/'.$this->routeId(1).'/cancel',
            [],
            $driver,
        )->assertStatus(200);

        $this->assertMatchesOperation(
            $response,
            '/api/v1/routes/{routeId}/cancel',
            'post',
        );

        /** @var array{route: array<string, mixed>} $body */
        $body = $response->json();

        self::assertArrayNotHasKey('trip', $body['route']);
        self::assertStringNotContainsString('trip', (string) json_encode($body));
    }

    // -------------------------------------------------- query efficiency

    /** A page costs the same number of queries whatever its size. */
    public function test_my_routes_does_not_query_a_trip_per_journey(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');

        // Everything is published while the departures are still ahead, and
        // only then does the clock move: publishing after travelling would be
        // refused for a departure already behind us.
        foreach ([1, 2, 3, 4] as $n) {
            $this->publish($driver, $n);
        }

        $at = $this->travelToDeparture();
        foreach ([1, 2, 3, 4] as $n) {
            app(StartTrip::class)($this->account($driver), $this->routeId($n), $at);
        }

        $one = $this->queriesFor(fn () => $this->myRoutes($driver, limit: 1));
        $many = $this->queriesFor(fn () => $this->myRoutes($driver, limit: 4));

        self::assertSame($one, $many, 'My Routes queries grew with the page');
    }

    public function test_my_seat_requests_does_not_query_a_trip_per_row(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $passenger = $this->member('+905322220000', 'Ayşe Demir');

        // Publish and ask while the journeys are still ahead; start them only
        // once everything exists. Publishing after the clock moved would be
        // refused for a departure already behind us, and asking for a seat on
        // a route that was never created is a 404.
        for ($n = 1; $n <= 4; $n++) {
            $this->publish($driver, $n);
            $this->postJson(
                '/api/v1/routes/'.$this->routeId($n).'/seat-requests',
                ['id' => $this->requestId($n)],
                $passenger,
            )->assertStatus(201);
        }

        $at = $this->travelToDeparture();
        for ($n = 1; $n <= 4; $n++) {
            app(StartTrip::class)($this->account($driver), $this->routeId($n), $at);
        }

        $one = $this->queriesFor(fn () => $this->mySeatRequests($passenger, limit: 1));
        $many = $this->queriesFor(fn () => $this->mySeatRequests($passenger, limit: 4));

        self::assertSame($one, $many, 'My Seat Requests queries grew with the page');
    }

    // ------------------------------------------ one journey is not another

    /**
     * CARRIES WEIGHT. The owner's list shows this journey, not another date's.
     *
     * A plan may make many journeys, and a projection that read "the route's
     * trip" would publish whichever one happened to exist — telling a driver
     * that today is under way because last week was.
     *
     * Planted, because no command in 16a can make a second journey on one
     * route; the guards are untouched.
     */
    public function test_my_routes_ignores_another_dates_journey(): void
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $this->publish($driver, 1);
        $route = Route::query()->findOrFail($this->routeId(1));

        $this->plantInProgress($route, $route->soleServiceDate()->subDay());

        $trip = $this->myRoutes($driver)['routes'][0]['trip'];

        self::assertSame('not_started', $trip['state']);
        self::assertNull($trip['started_at']);
    }

    /** And the passenger's own listing follows the date they asked about. */
    public function test_my_requests_ignores_another_dates_journey(): void
    {
        [, $routeId, $passenger] = $this->askedAndAccepted();
        $route = Route::query()->findOrFail($routeId);

        $this->plantInProgress($route, $route->soleServiceDate()->subDay());

        $trip = $this->mySeatRequests($passenger)['seat_requests'][0]['route']['trip'];

        self::assertSame('not_started', $trip['state']);
        self::assertNull($trip['started_at']);
    }

    /** A journey on a date of this route, written straight to the row. */
    private function plantInProgress(Route $route, CarbonImmutable $serviceDate): void
    {
        DB::table('trips')->insert([
            'id' => '01991e00-0000-7000-8000-0000000000ff',
            'route_id' => $route->id,
            'service_date' => $serviceDate->toDateString(),
            'status' => 'in_progress',
            'started_at' => $this->departed(),
            'completed_at' => null,
            'aborted_at' => null,
            'created_at' => $this->departed(),
            'updated_at' => $this->departed(),
        ]);
    }

    // ------------------------------------------------------------ fixtures

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function myRoutes(array $headers, int $limit = 20): array
    {
        $response = $this->getJson("/api/v1/me/routes?limit=$limit", $headers)
            ->assertStatus(200);

        $this->assertMatchesOperation($response, '/api/v1/me/routes');

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function mySeatRequests(array $headers, int $limit = 20): array
    {
        $response = $this->getJson("/api/v1/me/seat-requests?limit=$limit", $headers)
            ->assertStatus(200);

        $this->assertMatchesOperation($response, '/api/v1/me/seat-requests');

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * A driver whose one-off journey is under way.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function startedJourney(): array
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $this->publish($driver, 1);
        app(StartTrip::class)($this->account($driver), $this->routeId(1), $this->travelToDeparture());

        return [$driver, $this->routeId(1)];
    }

    /**
     * A published journey with one asking on it.
     *
     * @return array{0: array<string, string>, 1: string, 2: array<string, string>}
     */
    private function askedAndAccepted(bool $accept = true): array
    {
        $driver = $this->member('+905321110000', 'İrem Yılmaz');
        $passenger = $this->member('+905322220000', 'Ayşe Demir');
        $this->publish($driver, 1);

        $this->postJson(
            '/api/v1/routes/'.$this->routeId(1).'/seat-requests',
            ['id' => $this->requestId(1)],
            $passenger,
        )->assertStatus(201);

        if ($accept) {
            $this->postJson(
                '/api/v1/seat-requests/'.$this->requestId(1).'/accept',
                [],
                $driver,
            )->assertStatus(200);
        }

        return [$driver, $this->routeId(1), $passenger];
    }

    /**
     * The account behind a credential.
     *
     * These fixtures drive the commands directly rather than through the
     * endpoints, so that what this file proves — which surfaces publish the
     * lifecycle — does not depend on how the lifecycle is set. The endpoints
     * have their own tests in TripCommandEndpointTest.
     *
     * @param  array<string, string>  $headers
     */
    private function account(array $headers): Account
    {
        $id = $this->getJson('/api/v1/me', $headers)->assertStatus(200)->json('account.id');
        self::assertIsString($id);

        return Account::query()->findOrFail($id);
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
     * @param  array<string, string>  $headers
     */
    private function publish(array $headers, int $n): void
    {
        $this->postJson('/api/v1/routes', $this->publication($n), $headers)
            ->assertStatus(201);
    }

    /**
     * @return array<string, mixed>
     */
    private function publication(int $n): array
    {
        return [
            'id' => $this->routeId($n),
            'origin_place_id' => $this->place('kadikoy-iskele')->id,
            'destination_place_id' => $this->place('levent-metro')->id,
            'recurrence' => 'once',
            // Two minutes out, so travelling past it stays inside the access
            // token's fifteen-minute life. A departure days away would expire
            // the credential before the response could be read.
            'departure_date' => $this->departure()->format('Y-m-d'),
            'departure_time' => $this->departure()->format('H:i'),
            'seats_offered' => 3,
            'rules' => [
                'no_smoking' => true,
                'music_ok' => false,
                'no_pets' => false,
                'quiet' => false,
            ],
        ];
    }

    /** When the fixture journeys are due. */
    private function departure(): CarbonImmutable
    {
        return $this->publishedAt->addMinutes(2);
    }

    /** A moment after they were due. */
    private function departed(): CarbonImmutable
    {
        return $this->departure()->addMinutes(3);
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

    private function routeId(int $n): string
    {
        return sprintf('01991c00-0000-7000-8000-%012d', $n);
    }

    private function requestId(int $n): string
    {
        return sprintf('01991d00-0000-7000-8000-%012d', $n);
    }
}
