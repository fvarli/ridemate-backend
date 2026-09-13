<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Models\Trip;
use App\Routes\Recurrence;
use App\Routes\RouteStatus;
use App\Trips\TripStatus;
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
 * Starting, completing and abandoning one dated journey of a plan.
 *
 * THE WINDOW IS THE SUBJECT
 *
 * A plan's journey can be started from its departure instant until its calendar
 * day ends where the route is — one boundary at each end, each with its own
 * refusal, and each tested on both sides. The upper bound is what Phase 14
 * never needed: a one-off journey has no next occurrence for a late Start to be
 * confused with, and it keeps its open-ended window on purpose.
 *
 * WHY THE FIXTURES ARE WRITTEN RATHER THAN PUBLISHED
 *
 * Publication sets the timezone from configuration, so every route the API can
 * create shares one zone — and whether a driver's day has ended must be read
 * where the ROUTE is. Rows are therefore built directly, which is also the only
 * way to place a journey on a chosen weekday without waiting for one.
 *
 * The clock is frozen for every case and moved BEFORE the credential is issued
 * wherever a test needs a different instant: an access token lives fifteen
 * minutes, and travelling past that with one in hand would test expiry instead.
 */
final class DatedTripCommandEndpointTest extends TestCase
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
        'trips',
    ];

    /** Wednesday. The plan departs at 08:25 in the route's zone. */
    private const TUESDAY = '2026-09-15';

    private const WEDNESDAY = '2026-09-16';

    private const SATURDAY = '2026-09-19';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTestSmsSender();
        $this->seed(PilotPlaceSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------- the lower boundary

    public function test_a_plans_journey_cannot_be_started_before_it_leaves(): void
    {
        [$headers, $route] = $this->plan('08:24:59');

        $this->assertRefused(
            $this->start($headers, $route->id, self::WEDNESDAY),
            'departure_not_reached',
        );
    }

    /** CARRIES WEIGHT. The departure instant itself is inside the window. */
    public function test_a_plans_journey_starts_at_its_departure_instant(): void
    {
        [$headers, $route] = $this->plan('08:25:00');

        $response = $this->start($headers, $route->id, self::WEDNESDAY)->assertStatus(201);
        $this->assertMatchesSchema($response, 'TripEnvelope');

        $response->assertJsonPath('trip.state', 'in_progress')
            ->assertJsonPath('trip.completed_at', null)
            ->assertJsonPath('trip.aborted_at', null);

        $this->assertSame(self::WEDNESDAY, $this->storedServiceDate($route));
    }

    public function test_a_plans_journey_starts_later_the_same_day(): void
    {
        [$headers, $route] = $this->plan('23:59:59');

        $this->start($headers, $route->id, self::WEDNESDAY)
            ->assertStatus(201)
            ->assertJsonPath('trip.state', 'in_progress');
    }

    // ------------------------------------------------- the upper boundary

    /**
     * CARRIES WEIGHT. THE REASON `service_date_passed` EXISTS.
     *
     * One second into Thursday, Wednesday's journey is over where the route is.
     * Starting it then would record a journey as beginning on a date behind the
     * driver, and Thursday's journey is a different one with its own command.
     */
    public function test_a_plans_journey_cannot_be_started_once_its_day_is_over(): void
    {
        [$headers, $route] = $this->plan('00:00:01', day: '2026-09-17');

        $this->assertRefused(
            $this->start($headers, $route->id, self::WEDNESDAY),
            'service_date_passed',
        );
    }

    /**
     * CARRIES WEIGHT. The day ends where the ROUTE is, not where the server is.
     *
     * At 01:00 on Thursday in Istanbul it is still 15:00 on Wednesday in Los
     * Angeles, so the Los Angeles plan's Wednesday journey is still startable
     * while the Istanbul one's is not. A server-local date would refuse both,
     * and would look perfectly correct in a pilot where every route is
     * Istanbul's.
     */
    public function test_the_day_ends_in_the_routes_own_timezone(): void
    {
        $this->clockAt('2026-09-17T01:00:00+03:00');
        [$headers, $driver] = $this->driver();

        $istanbul = $this->route($driver, 1, timezone: 'Europe/Istanbul');
        $angeles = $this->route($driver, 2, timezone: 'America/Los_Angeles');

        $this->assertRefused(
            $this->start($headers, $istanbul->id, self::WEDNESDAY),
            'service_date_passed',
        );

        $this->start($headers, $angeles->id, self::WEDNESDAY)
            ->assertStatus(201)
            ->assertJsonPath('trip.state', 'in_progress');
    }

    // ------------------------------------------------------- addressing

    public function test_a_day_the_plan_does_not_run_is_a_404(): void
    {
        [$headers, $route] = $this->plan('09:00:00', day: self::SATURDAY);

        $this->start($headers, $route->id, self::SATURDAY)->assertStatus(404);
        $this->complete($headers, $route->id, self::SATURDAY)->assertStatus(404);
        $this->abort($headers, $route->id, self::SATURDAY)->assertStatus(404);
    }

    public function test_an_impossible_date_and_a_foreign_route_are_the_same_404(): void
    {
        [$headers, $route] = $this->plan('09:00:00');
        $stranger = $this->createAccount('+905329990000');
        $theirs = $this->route($stranger, 9);

        $this->start($headers, $route->id, '2026-02-30')->assertStatus(404);
        $this->start($headers, $theirs->id, self::WEDNESDAY)->assertStatus(404);
        $this->start($headers, $this->routeId(99), self::WEDNESDAY)->assertStatus(404);
    }

    public function test_a_dated_command_requires_a_credential(): void
    {
        $path = '/api/v1/routes/'.$this->routeId(1).'/journeys/'.self::WEDNESDAY.'/trip/start';

        $this->postJson($path, [])->assertStatus(401);
    }

    public function test_a_withdrawn_plan_cannot_have_a_new_journey_started(): void
    {
        [$headers, $route] = $this->plan('09:00:00', status: RouteStatus::Cancelled);

        $this->assertRefused(
            $this->start($headers, $route->id, self::WEDNESDAY),
            'route_unavailable',
        );
    }

    // ------------------------------------------------------------ replay

    public function test_repeating_start_answers_200_with_the_original_instant(): void
    {
        [$headers, $route] = $this->plan('08:25:00');

        $first = $this->start($headers, $route->id, self::WEDNESDAY)->assertStatus(201);
        $startedAt = $first->json('trip.started_at');

        $this->start($headers, $route->id, self::WEDNESDAY)
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'in_progress')
            ->assertJsonPath('trip.started_at', $startedAt);
    }

    /**
     * CARRIES WEIGHT. THE REASON EXISTING-TRIP RESOLUTION COMES FIRST.
     *
     * A journey started on Tuesday evening is still under way at one in the
     * morning on Wednesday, and Tuesday is over. If Start re-ran its timing
     * checks for a trip that already exists, the driver replaying the command
     * they already succeeded at — a lost response, a second device — would be
     * told `service_date_passed` about the journey they are sitting in.
     *
     * Started through the endpoint and replayed after a real seventeen-hour
     * jump, with a fresh credential because a token does not live that long.
     */
    public function test_a_running_journey_still_replays_after_its_day_ends(): void
    {
        [$headers, $route] = $this->plan('08:25:00', day: self::TUESDAY);

        $first = $this->start($headers, $route->id, self::TUESDAY)->assertStatus(201);
        $startedAt = $first->json('trip.started_at');

        $this->clockAt('2026-09-16T01:00:00+03:00');
        [$headers] = $this->driver();

        $this->start($headers, $route->id, self::TUESDAY)
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'in_progress')
            ->assertJsonPath('trip.started_at', $startedAt);
    }

    public function test_a_finished_or_abandoned_journey_cannot_be_started_again(): void
    {
        [$headers, $route] = $this->plan('09:00:00');

        $this->trip($route, self::WEDNESDAY, TripStatus::Completed);
        $this->assertRefused(
            $this->start($headers, $route->id, self::WEDNESDAY),
            'already_completed',
        );

        Trip::query()->where('route_id', $route->id)->delete();
        $this->trip($route, self::WEDNESDAY, TripStatus::Aborted);
        $this->assertRefused(
            $this->start($headers, $route->id, self::WEDNESDAY),
            'already_aborted',
        );
    }

    // ------------------------------------------------------ ending a day

    /**
     * CARRIES WEIGHT. Ending has no calendar bound at all.
     *
     * Start is bounded because it CREATES something dated. A journey that
     * already exists is ended on the day it is ended, and a driver whose
     * journey ran past midnight must still be able to say how it went.
     */
    public function test_a_journey_can_be_completed_or_abandoned_after_its_day(): void
    {
        // One in the morning on Wednesday: Tuesday's journey is long over as a
        // date, and both of its endings must still be reachable.
        $this->clockAt('2026-09-16T01:00:00+03:00');
        [$headers, $driver] = $this->driver();

        $completing = $this->route($driver, 1);
        $aborting = $this->route($driver, 2);
        $this->trip($completing, self::TUESDAY, TripStatus::InProgress);
        $this->trip($aborting, self::TUESDAY, TripStatus::InProgress);

        $completed = $this->complete($headers, $completing->id, self::TUESDAY)
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'completed');
        self::assertNotNull($completed->json('trip.completed_at'));

        $aborted = $this->abort($headers, $aborting->id, self::TUESDAY)
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'aborted');
        self::assertNotNull($aborted->json('trip.aborted_at'));
    }

    /**
     * CARRIES WEIGHT. A cancelled plan must not strand a running journey.
     *
     * Cancelling says no FUTURE journey of this plan will run. It does not
     * rewrite one already under way, and the driver on it has to be able to
     * finish or abandon it — otherwise a withdrawal leaves a trip `in_progress`
     * for ever with no honest way out.
     */
    public function test_a_withdrawn_plan_can_still_have_its_running_journey_ended(): void
    {
        $this->clockAt('2026-09-16T10:00:00+03:00');
        [$headers, $driver] = $this->driver();

        $completing = $this->route($driver, 1, status: RouteStatus::Cancelled);
        $aborting = $this->route($driver, 2, status: RouteStatus::Cancelled);
        $this->trip($completing, self::WEDNESDAY, TripStatus::InProgress);
        $this->trip($aborting, self::WEDNESDAY, TripStatus::InProgress);

        $this->complete($headers, $completing->id, self::WEDNESDAY)
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'completed');

        $this->abort($headers, $aborting->id, self::WEDNESDAY)
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'aborted');
    }

    public function test_repeating_an_ending_preserves_its_instant(): void
    {
        [$headers, $route] = $this->plan('09:00:00');
        $this->trip($route, self::WEDNESDAY, TripStatus::InProgress);

        $first = $this->complete($headers, $route->id, self::WEDNESDAY)->assertStatus(200);
        $completedAt = $first->json('trip.completed_at');

        $this->complete($headers, $route->id, self::WEDNESDAY)
            ->assertStatus(200)
            ->assertJsonPath('trip.completed_at', $completedAt);
    }

    public function test_ending_a_journey_nobody_started_is_refused(): void
    {
        [$headers, $route] = $this->plan('09:00:00');

        $this->assertRefused(
            $this->complete($headers, $route->id, self::WEDNESDAY),
            'trip_not_started',
        );
        $this->assertRefused(
            $this->abort($headers, $route->id, self::WEDNESDAY),
            'trip_not_started',
        );
    }

    public function test_neither_ending_can_overwrite_the_other(): void
    {
        [$headers, $route] = $this->plan('09:00:00');

        $this->trip($route, self::WEDNESDAY, TripStatus::Completed);
        $this->assertRefused(
            $this->abort($headers, $route->id, self::WEDNESDAY),
            'already_completed',
        );

        Trip::query()->where('route_id', $route->id)->delete();
        $this->trip($route, self::WEDNESDAY, TripStatus::Aborted);
        $this->assertRefused(
            $this->complete($headers, $route->id, self::WEDNESDAY),
            'already_aborted',
        );
    }

    /**
     * CARRIES WEIGHT. One day of a plan is not another.
     *
     * Tuesday finished; Wednesday has not been started. A lookup by route alone
     * would hand Wednesday's command Tuesday's finished trip and answer
     * `already_completed` for a journey nobody has begun — a refusal about the
     * wrong day. Every command is checked, because each has its own lookup.
     */
    public function test_one_days_journey_never_answers_for_another(): void
    {
        [$headers, $route] = $this->plan('09:00:00');
        $this->trip($route, self::TUESDAY, TripStatus::Completed);

        $this->start($headers, $route->id, self::WEDNESDAY)
            ->assertStatus(201)
            ->assertJsonPath('trip.state', 'in_progress');

        Trip::query()->where('service_date', self::WEDNESDAY)->delete();

        $this->assertRefused(
            $this->complete($headers, $route->id, self::WEDNESDAY),
            'trip_not_started',
        );
        $this->assertRefused(
            $this->abort($headers, $route->id, self::WEDNESDAY),
            'trip_not_started',
        );
    }

    // ------------------------------------------------ the older endpoints

    /**
     * CARRIES WEIGHT. The route-only form still refuses a plan.
     *
     * All three of them, and with the reason that is actually true: this
     * endpoint cannot address a plan's journey. Nothing may pick today, the
     * next occurrence, or the most recent one for a caller who named none.
     */
    public function test_the_route_only_endpoints_refuse_a_plan(): void
    {
        [$headers, $route] = $this->plan('09:00:00');

        foreach (['start', 'complete', 'abort'] as $command) {
            $this->postJson("/api/v1/routes/{$route->id}/trip/$command", [], $headers)
                ->assertStatus(409)
                ->assertJsonPath('error.details.reason', 'recurring_route_unsupported');
        }
    }

    /**
     * CARRIES WEIGHT. A plan's journey is never started by an unnamed command.
     *
     * The failure this pins: the older endpoint quietly resolving "today" and
     * creating a trip. Nothing may exist afterwards.
     */
    public function test_the_route_only_endpoint_creates_no_trip_for_a_plan(): void
    {
        [$headers, $route] = $this->plan('09:00:00');

        $this->postJson("/api/v1/routes/{$route->id}/trip/start", [], $headers)
            ->assertStatus(409);

        self::assertSame(0, Trip::query()->where('route_id', $route->id)->count());
    }

    // ----------------------------------------------- one-off regression

    /**
     * CARRIES WEIGHT. A one-off journey keeps its open-ended window.
     *
     * Days after its departure it can still be started, through either form.
     * The plan's same-day upper bound must not be retrofitted onto it: a
     * one-off journey has no next occurrence for a late Start to be confused
     * with, and narrowing it would take away something drivers have today.
     */
    public function test_a_one_off_journey_can_still_be_started_days_later(): void
    {
        $this->clockAt('2026-09-21T09:00:00+03:00');
        [$headers, $driver] = $this->driver();

        $first = $this->route($driver, 1, Recurrence::Once, date: self::WEDNESDAY);
        $second = $this->route($driver, 2, Recurrence::Once, date: self::WEDNESDAY);

        // The older form, exactly as Phase 14 served it.
        $this->postJson("/api/v1/routes/{$first->id}/trip/start", [], $headers)
            ->assertStatus(201)
            ->assertJsonPath('trip.state', 'in_progress');

        // And the dated form, naming the route's own day.
        $this->start($headers, $second->id, self::WEDNESDAY)
            ->assertStatus(201)
            ->assertJsonPath('trip.state', 'in_progress');
    }

    public function test_a_one_off_journey_still_refuses_an_early_start(): void
    {
        $this->clockAt('2026-09-16T08:24:59+03:00');
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Once, date: self::WEDNESDAY);

        $this->assertRefused(
            $this->start($headers, $route->id, self::WEDNESDAY),
            'departure_not_reached',
        );
        $this->assertRefused(
            $this->postJson("/api/v1/routes/{$route->id}/trip/start", [], $headers),
            'departure_not_reached',
        );
    }

    public function test_a_one_off_journey_refuses_any_day_but_its_own(): void
    {
        $this->clockAt('2026-09-21T09:00:00+03:00');
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Once, date: self::WEDNESDAY);

        $this->start($headers, $route->id, self::TUESDAY)->assertStatus(404);
        $this->complete($headers, $route->id, self::TUESDAY)->assertStatus(404);
        $this->abort($headers, $route->id, self::TUESDAY)->assertStatus(404);
    }

    /** The two forms address the same journey, so the second is a replay. */
    public function test_the_two_forms_address_one_journey_for_a_one_off_route(): void
    {
        $this->clockAt('2026-09-16T09:00:00+03:00');
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Once, date: self::WEDNESDAY);

        $first = $this->postJson("/api/v1/routes/{$route->id}/trip/start", [], $headers)
            ->assertStatus(201);

        $this->start($headers, $route->id, self::WEDNESDAY)
            ->assertStatus(200)
            ->assertJsonPath('trip.started_at', $first->json('trip.started_at'));

        self::assertSame(1, Trip::query()->where('route_id', $route->id)->count());
    }

    // -------------------------------------------------------- fixtures

    /**
     * A published weekday plan, and the clock at a chosen moment.
     *
     * @return array{0: array<string, string>, 1: Route}
     */
    private function plan(
        string $time,
        string $day = self::WEDNESDAY,
        RouteStatus $status = RouteStatus::Published,
    ): array {
        $this->clockAt($day.'T'.$time.'+03:00');
        [$headers, $driver] = $this->driver();

        return [$headers, $this->route($driver, 1, status: $status)];
    }

    private function clockAt(string $instant): void
    {
        $this->travelTo(CarbonImmutable::parse($instant));
    }

    /**
     * @return array{0: array<string, string>, 1: Account}
     */
    private function driver(string $phone = '+905321110000'): array
    {
        $headers = $this->bearer($this->signIn($phone)['access_token']);
        $account = Account::query()->where('phone_e164', $phone)->sole();

        return [$headers, $account];
    }

    private function route(
        Account $driver,
        int $n,
        Recurrence $recurrence = Recurrence::Weekdays,
        ?string $date = null,
        string $timezone = 'Europe/Istanbul',
        RouteStatus $status = RouteStatus::Published,
    ): Route {
        $route = new Route;
        $route->id = $this->routeId($n);
        $route->account_id = $driver->id;
        $route->origin_place_id = $this->place('kadikoy-iskele')->id;
        $route->destination_place_id = $this->place('levent-metro')->id;
        $route->recurrence = $recurrence;
        $route->departure_date = $date === null ? null : CarbonImmutable::parse($date);
        $route->departure_time = '08:25:00';
        $route->timezone = $timezone;
        $route->seats_offered = 3;
        $route->rule_no_smoking = true;
        $route->rule_music_ok = false;
        $route->rule_no_pets = false;
        $route->rule_quiet = false;
        $route->status = $status;
        $route->published_at = CarbonImmutable::now();
        $route->cancelled_at = $status === RouteStatus::Cancelled ? CarbonImmutable::now() : null;
        $route->save();

        return $route;
    }

    private function trip(Route $route, string $serviceDate, TripStatus $status): Trip
    {
        $trip = new Trip;
        $trip->route_id = $route->id;
        $trip->service_date = CarbonImmutable::parse($serviceDate);
        $trip->status = $status;
        $trip->started_at = CarbonImmutable::parse($serviceDate.' 08:25:00');
        $trip->completed_at = $status === TripStatus::Completed ? CarbonImmutable::now() : null;
        $trip->aborted_at = $status === TripStatus::Aborted ? CarbonImmutable::now() : null;
        $trip->save();

        return $trip;
    }

    private function storedServiceDate(Route $route): string
    {
        $trip = Trip::query()->where('route_id', $route->id)->sole();

        return $trip->service_date->toDateString();
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function start(array $headers, string $routeId, string $day): TestResponse
    {
        return $this->command($headers, $routeId, $day, 'start');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function complete(array $headers, string $routeId, string $day): TestResponse
    {
        return $this->command($headers, $routeId, $day, 'complete');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function abort(array $headers, string $routeId, string $day): TestResponse
    {
        return $this->command($headers, $routeId, $day, 'abort');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function command(
        array $headers,
        string $routeId,
        string $day,
        string $command,
    ): TestResponse {
        return $this->postJson(
            "/api/v1/routes/$routeId/journeys/$day/trip/$command",
            [],
            $headers,
        );
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    private function assertRefused(TestResponse $response, string $reason): void
    {
        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict')
            ->assertJsonPath('error.details.reason', $reason)
            // Seat requests carry this; a trip refusal does not, because the
            // caller owns the journey and can read its lifecycle themselves.
            ->assertJsonMissingPath('error.details.current_status');
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
