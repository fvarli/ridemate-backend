<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Journeys\JourneyCursor;
use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Models\Trip;
use App\Routes\Recurrence;
use App\Routes\RouteCursor;
use App\Routes\RouteStatus;
use App\Trips\TripStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Contract\ValidatesTheContract;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * The driver's dated journeys, over HTTP.
 *
 * WHY THE FIXTURES ARE WRITTEN RATHER THAN PUBLISHED
 *
 * Publication sets the timezone from configuration, so every route the API can
 * create shares one zone — and the thing most worth testing here is that a
 * driver's "today" is read where the ROUTE is, not where the process is. Rows
 * are therefore built directly, which is also the only way to put a completed
 * journey in the past without travelling the clock through a publication that
 * would refuse it.
 *
 * The clock is frozen for every case. "Today" is the whole subject, and a test
 * that read the wall clock twice could straddle a midnight and fail once a day
 * for reasons that have nothing to do with the code.
 */
final class DriverJourneysEndpointTest extends TestCase
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

    private const FEED = '/api/v1/me/journeys';

    /** A Wednesday, so a weekday plan runs and the day before it does too. */
    private const NOW = '2026-09-16T09:00:00+03:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTestSmsSender();
        $this->seed(PilotPlaceSeeder::class);
        $this->travelTo(CarbonImmutable::parse(self::NOW));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // -------------------------------------------------------------- branch A

    public function test_the_feed_requires_a_credential(): void
    {
        $this->getJson(self::FEED)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_driver_with_no_routes_gets_an_empty_page(): void
    {
        [$headers] = $this->driver();

        $this->feed($headers)
            ->assertStatus(200)
            ->assertJsonPath('journeys', [])
            ->assertJsonPath('next_cursor', null);
    }

    /** THE REASON BRANCH A EXISTS: a journey still to be started is in the feed. */
    public function test_a_recurring_plan_running_today_appears_as_not_started(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);

        $response = $this->feed($headers)->assertStatus(200);
        $this->assertMatchesSchema($response, 'JourneyPage');
        $body = $response->json();

        self::assertSame(
            [['route_id' => $route->id, 'service_date' => '2026-09-16', 'state' => 'not_started']],
            $this->summarise($body),
        );
    }

    /**
     * CARRIES WEIGHT. The whole shape, asserted rather than sampled.
     *
     * A denylist passes whatever a future change adds. This says exactly what a
     * journey carries, so a field reaches a client only when somebody edits
     * this line — and in particular nothing about the plan (recurrence, seats,
     * rules, `published_at`) and nothing about anybody else.
     */
    public function test_a_journey_carries_exactly_the_documented_fields(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);

        /** @var array{journeys: list<array<string, mixed>>} $body */
        $body = $this->feed($headers)->json();

        self::assertSame(['journeys', 'next_cursor'], array_keys($body));
        self::assertSame([
            'route_id', 'service_date', 'origin', 'destination',
            'departure_time', 'timezone', 'route_status', 'trip',
        ], array_keys($body['journeys'][0]));

        /** @var array<string, mixed> $journey */
        $journey = $body['journeys'][0];
        foreach ([
            'id', 'recurrence', 'seats_offered', 'rules', 'departure_date',
            'departure_state', 'published_at', 'cancelled_at', 'status',
            'driver', 'passengers', 'passenger_count', 'accepted_seats',
        ] as $absent) {
            self::assertArrayNotHasKey(
                $absent,
                $journey,
                "a journey carried `$absent`, which this projection must not publish",
            );
        }

        // The dated read answers with the same projection, and no envelope.
        $single = $this->journey($headers, $route->id, '2026-09-16')->json();
        self::assertSame($journey, $single);
    }

    public function test_a_plan_that_does_not_run_today_is_absent(): void
    {
        // Saturday, so the weekday plan has no journey — the clock is moved
        // rather than the plan changed, which is the case a driver meets. It
        // moves BEFORE the credential is issued: an access token lives fifteen
        // minutes, and travelling past that would test expiry instead.
        $this->travelTo(CarbonImmutable::parse('2026-09-19T09:00:00+03:00'));
        [$headers, $driver] = $this->driver();
        $this->route($driver, 1, Recurrence::Weekdays);

        $this->feed($headers)->assertJsonPath('journeys', []);
    }

    public function test_a_one_off_route_scheduled_today_appears(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Once, date: '2026-09-16');

        $this->feed($headers)
            ->assertJsonCount(1, 'journeys')
            ->assertJsonPath('journeys.0.route_id', $route->id)
            ->assertJsonPath('journeys.0.service_date', '2026-09-16');
    }

    /** CARRIES WEIGHT. The feed is today's, not a schedule. */
    public function test_a_one_off_route_on_another_day_is_absent(): void
    {
        [$headers, $driver] = $this->driver();
        $this->route($driver, 1, Recurrence::Once, date: '2026-09-17');

        $this->feed($headers)->assertJsonPath('journeys', []);
    }

    public function test_a_cancelled_plan_has_no_journey_today(): void
    {
        [$headers, $driver] = $this->driver();
        $this->route($driver, 1, Recurrence::Weekdays, status: RouteStatus::Cancelled);

        $this->feed($headers)->assertJsonPath('journeys', []);
    }

    // -------------------------------------------------------------- branch B

    /**
     * CARRIES WEIGHT. Yesterday is over, whichever way it ended.
     *
     * Three endings, each its own case: a transaction that dropped one branch
     * would otherwise still pass on the other two.
     */
    public function test_a_finished_or_unstarted_past_journey_is_absent(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);

        foreach ([TripStatus::Completed, TripStatus::Aborted] as $status) {
            Trip::query()->where('route_id', $route->id)->delete();
            $this->trip($route, '2026-09-15', $status);

            // Today's journey is legitimately there — the plan runs today. What
            // must not be there is yesterday's.
            $days = array_column(
                $this->summarise($this->feed($headers)->json()),
                'service_date',
            );

            self::assertSame(
                ['2026-09-16'],
                $days,
                "a {$status->value} journey from yesterday reached the feed",
            );
        }
    }

    /**
     * CARRIES WEIGHT. And a yesterday nobody started is absent too.
     *
     * There is no trip row for it at all, so the only way it could appear is
     * branch A reaching backwards — which it must not.
     */
    public function test_a_past_journey_nobody_started_is_absent(): void
    {
        // Thursday, so both Wednesday and today are weekdays the plan runs on.
        $this->travelTo(CarbonImmutable::parse('2026-09-17T09:00:00+03:00'));
        [$headers, $driver] = $this->driver();
        $this->route($driver, 1, Recurrence::Weekdays);

        $days = array_column($this->summarise($this->feed($headers)->json()), 'service_date');

        self::assertSame(['2026-09-17'], $days);
    }

    /** THE REASON BRANCH B EXISTS: a journey that crossed midnight. */
    public function test_a_past_journey_still_under_way_is_included(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);
        $this->trip($route, '2026-09-15', TripStatus::InProgress);

        self::assertSame(
            [
                ['route_id' => $route->id, 'service_date' => '2026-09-16', 'state' => 'not_started'],
                ['route_id' => $route->id, 'service_date' => '2026-09-15', 'state' => 'in_progress'],
            ],
            $this->summarise($this->feed($headers)->json()),
        );
    }

    /**
     * CARRIES WEIGHT. A cancelled plan does not strand a journey under way.
     *
     * Branch B must ask nothing about the route's status. A driver whose plan
     * was withdrawn mid-journey still has to end the one they are on.
     */
    public function test_an_in_progress_journey_survives_its_plan_being_cancelled(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays, status: RouteStatus::Cancelled);
        $this->trip($route, '2026-09-15', TripStatus::InProgress);

        $this->feed($headers)
            ->assertJsonCount(1, 'journeys')
            ->assertJsonPath('journeys.0.service_date', '2026-09-15')
            ->assertJsonPath('journeys.0.route_status', 'cancelled')
            ->assertJsonPath('journeys.0.trip.state', 'in_progress');
    }

    /** CARRIES WEIGHT. Both branches find it; it is still one journey. */
    public function test_todays_started_journey_appears_exactly_once(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);
        $this->trip($route, '2026-09-16', TripStatus::InProgress);

        self::assertSame(
            [['route_id' => $route->id, 'service_date' => '2026-09-16', 'state' => 'in_progress']],
            $this->summarise($this->feed($headers)->json()),
        );
    }

    /** One plan, two days, both under way — two journeys, not one. */
    public function test_one_plan_may_hold_several_dated_journeys_at_once(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);
        $this->trip($route, '2026-09-14', TripStatus::InProgress);
        $this->trip($route, '2026-09-15', TripStatus::InProgress);

        self::assertSame(
            ['2026-09-16', '2026-09-15', '2026-09-14'],
            array_column($this->summarise($this->feed($headers)->json()), 'service_date'),
        );
    }

    public function test_another_drivers_journey_never_appears(): void
    {
        [$headers] = $this->driver();
        $stranger = $this->createAccount('+905329990000');
        $theirs = $this->route($stranger, 9, Recurrence::Weekdays);
        $this->trip($theirs, '2026-09-15', TripStatus::InProgress);

        $this->feed($headers)->assertJsonPath('journeys', []);
    }

    // ------------------------------------------------------------- timezone

    /**
     * CARRIES WEIGHT. Today is the ROUTE's today.
     *
     * At 21:00 in Istanbul it is already the next day in Auckland and still the
     * previous one in Los Angeles. Three routes, three zones, one instant: each
     * must be asked about its own calendar day. A server-local date would give
     * all three the same answer and would look perfectly correct in a pilot
     * where every route is Istanbul's.
     */
    public function test_today_is_read_in_each_routes_own_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16T21:00:00+03:00'));
        [$headers, $driver] = $this->driver();

        // 2026-09-17 in Auckland, 2026-09-16 in Istanbul, 2026-09-16 in Los
        // Angeles — and all three are weekdays, so all three run.
        $auckland = $this->route($driver, 1, Recurrence::Weekdays, timezone: 'Pacific/Auckland');
        $istanbul = $this->route($driver, 2, Recurrence::Weekdays, timezone: 'Europe/Istanbul');
        $angeles = $this->route($driver, 3, Recurrence::Weekdays, timezone: 'America/Los_Angeles');

        $days = [];
        foreach ($this->summarise($this->feed($headers)->json()) as $row) {
            $days[$row['route_id']] = $row['service_date'];
        }

        self::assertSame('2026-09-17', $days[$auckland->id] ?? null);
        self::assertSame('2026-09-16', $days[$istanbul->id] ?? null);
        self::assertSame('2026-09-16', $days[$angeles->id] ?? null);
    }

    /**
     * CARRIES WEIGHT. And the weekday is read there too.
     *
     * Friday 21:00 in Istanbul is already Saturday in Auckland, so the Auckland
     * plan has no journey while the Istanbul one still does.
     */
    public function test_a_weekend_in_the_routes_zone_is_a_weekend_for_that_route(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-18T21:00:00+03:00'));
        [$headers, $driver] = $this->driver();

        $auckland = $this->route($driver, 1, Recurrence::Weekdays, timezone: 'Pacific/Auckland');
        $istanbul = $this->route($driver, 2, Recurrence::Weekdays, timezone: 'Europe/Istanbul');

        $ids = array_column($this->summarise($this->feed($headers)->json()), 'route_id');

        self::assertSame([$istanbul->id], $ids);
        self::assertNotContains($auckland->id, $ids);
    }

    // ------------------------------------------------------------ pagination

    /** Deterministic, and the route id breaks a tie on one service date. */
    public function test_the_feed_is_ordered_by_service_date_then_route_id(): void
    {
        [$headers, $driver] = $this->driver();
        $first = $this->route($driver, 1, Recurrence::Weekdays);
        $second = $this->route($driver, 2, Recurrence::Weekdays);

        $ids = array_column($this->summarise($this->feed($headers)->json()), 'route_id');

        // Descending on the id, both on today.
        self::assertSame([$second->id, $first->id], $ids);
    }

    public function test_a_page_continues_from_its_cursor_without_gaps_or_repeats(): void
    {
        [$headers, $driver] = $this->driver();
        foreach ([1, 2, 3] as $n) {
            $this->route($driver, $n, Recurrence::Weekdays);
        }

        $first = $this->feed($headers, limit: 2)->assertJsonCount(2, 'journeys')->json();
        self::assertIsArray($first);
        $cursor = $first['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $second = $this->feed($headers, limit: 2, cursor: $cursor)
            ->assertJsonCount(1, 'journeys')
            ->assertJsonPath('next_cursor', null)
            ->json();

        $seen = array_merge(
            array_column($this->summarise($first), 'route_id'),
            array_column($this->summarise($second), 'route_id'),
        );

        self::assertCount(3, $seen);
        self::assertSame($seen, array_values(array_unique($seen)));
    }

    /**
     * CARRIES WEIGHT. Journeys the DOMAIN steps over do not eat page slots.
     *
     * On a Saturday every weekday plan is a candidate — the query admits them
     * all and `RouteDeparture` is what rejects them — while the journeys still
     * under way from the week are the only eligible rows. A plain `LIMIT n`
     * over the candidates would hand back a page short by however many the
     * domain threw away, sometimes an empty one with perfectly good journeys
     * sitting right behind it.
     *
     * The rejected candidates have to be domain-rejected rather than
     * query-rejected for this to test anything: a one-off route on another day
     * never becomes a candidate at all, so using those would have proved only
     * that the `WHERE` clause works.
     */
    public function test_ineligible_candidates_do_not_consume_page_slots(): void
    {
        // Saturday: no weekday plan runs, so branch A yields five candidates
        // and every one of them is thrown away by the domain.
        $this->travelTo(CarbonImmutable::parse('2026-09-19T09:00:00+03:00'));
        [$headers, $driver] = $this->driver();

        foreach ([1, 2, 3, 4, 5] as $n) {
            $route = $this->route($driver, $n, Recurrence::Weekdays);

            // Three of them are still under way from earlier in the week.
            if ($n % 2 === 1) {
                $this->trip($route, '2026-09-16', TripStatus::InProgress);
            }
        }

        $first = $this->feed($headers, limit: 2)->assertJsonCount(2, 'journeys')->json();
        self::assertIsArray($first);
        $cursor = $first['next_cursor'] ?? null;
        self::assertIsString($cursor, 'the page ended early because rejected candidates took slots');

        $this->feed($headers, limit: 2, cursor: $cursor)
            ->assertJsonCount(1, 'journeys')
            ->assertJsonPath('next_cursor', null);

        // And asking for all three at once finds all three and knows it is done.
        $this->feed($headers, limit: 3)
            ->assertJsonCount(3, 'journeys')
            ->assertJsonPath('next_cursor', null);
    }

    /**
     * CARRIES WEIGHT. A one-off route's day is enforced by the domain too.
     *
     * The query narrows one-off routes to their own date, which is a pre-filter
     * rather than the rule — and this is what says so. With the narrowing gone
     * the answer must not change, because `RouteDeparture` is still asked.
     */
    public function test_a_one_off_routes_day_is_the_domains_answer_not_only_the_querys(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Once, date: '2026-09-17');

        // Reached through the domain directly, with no query narrowing in the
        // way: tomorrow's one-off journey is not today's.
        self::assertFalse($route->runsOn(CarbonImmutable::parse('2026-09-16')));
        self::assertTrue($route->runsOn(CarbonImmutable::parse('2026-09-17')));

        $this->feed($headers)->assertJsonPath('journeys', []);
    }

    public function test_a_cursor_from_another_feed_is_refused(): void
    {
        [$headers] = $this->driver();

        $foreign = (new RouteCursor(
            CarbonImmutable::now(),
            '01991b00-0000-7000-8000-000000000001',
        ))->encode();

        $this->feed($headers, cursor: $foreign)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.cursor.0', 'The cursor is not valid.');
    }

    public function test_a_tampered_cursor_is_refused(): void
    {
        [$headers] = $this->driver();

        foreach ([
            'not-encrypted-at-all',
            Crypt::encryptString('rm.journeys.v1|2026-02-30|01991b00-0000-7000-8000-000000000001'),
            Crypt::encryptString('rm.journeys.v2|2026-09-16|01991b00-0000-7000-8000-000000000001'),
            Crypt::encryptString('rm.journeys.v1|2026-09-16|not-a-uuid'),
            Crypt::encryptString('rm.journeys.v1|2026-09-16'),
        ] as $cursor) {
            $this->feed($headers, cursor: $cursor)
                ->assertStatus(422)
                ->assertJsonPath('error.details.cursor.0', 'The cursor is not valid.');
        }
    }

    /** A cursor carries the day AS a day, not as an instant in some zone. */
    public function test_a_cursor_round_trips_its_service_date(): void
    {
        $cursor = new JourneyCursor(
            CarbonImmutable::parse('2026-09-16'),
            '01991b00-0000-7000-8000-000000000001',
        );

        $decoded = JourneyCursor::decode($cursor->encode());

        self::assertInstanceOf(JourneyCursor::class, $decoded);
        self::assertSame('2026-09-16', $decoded->serviceDate->format('Y-m-d'));
        self::assertSame($cursor->routeId, $decoded->routeId);
    }

    // ------------------------------------------------------------------ N+1

    public function test_the_feed_does_not_query_per_journey(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);
        $this->trip($route, '2026-09-15', TripStatus::InProgress);

        $one = $this->queriesFor(fn () => $this->feed($headers, limit: 1));

        foreach ([2, 3, 4] as $n) {
            $more = $this->route($driver, $n, Recurrence::Weekdays);
            $this->trip($more, '2026-09-15', TripStatus::InProgress);
        }

        $many = $this->queriesFor(fn () => $this->feed($headers, limit: 20));

        self::assertSame($one, $many, 'the journey feed queried per journey');
    }

    // ------------------------------------------------------- the dated read

    public function test_a_dated_read_answers_not_started_for_a_day_with_no_trip(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);

        // A Tuesday well in the past, which the feed would never show.
        $response = $this->journey($headers, $route->id, '2026-09-08')->assertStatus(200);
        $this->assertMatchesSchema($response, 'Journey');

        $response
            ->assertJsonPath('route_id', $route->id)
            ->assertJsonPath('service_date', '2026-09-08')
            ->assertJsonPath('trip.state', 'not_started')
            ->assertJsonPath('trip.started_at', null);
    }

    public function test_a_dated_read_answers_a_one_off_routes_own_day(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Once, date: '2026-09-21');

        $this->journey($headers, $route->id, '2026-09-21')
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'not_started');
    }

    public function test_a_dated_read_returns_that_days_trip(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);

        foreach ([
            '2026-09-14' => TripStatus::Completed,
            '2026-09-15' => TripStatus::Aborted,
            '2026-09-16' => TripStatus::InProgress,
        ] as $day => $status) {
            $this->trip($route, $day, $status);
        }

        foreach ([
            '2026-09-14' => 'completed',
            '2026-09-15' => 'aborted',
            '2026-09-16' => 'in_progress',
        ] as $day => $state) {
            $this->journey($headers, $route->id, $day)
                ->assertStatus(200)
                ->assertJsonPath('service_date', $day)
                ->assertJsonPath('trip.state', $state);
        }
    }

    /**
     * CARRIES WEIGHT. Another day's trip must not answer for this one.
     *
     * The failure this pins is a lookup scoped by `route_id` alone, which would
     * hand back whichever trip the database found first — right about a day
     * nobody asked about.
     */
    public function test_a_dated_read_never_borrows_another_days_trip(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);
        $this->trip($route, '2026-09-15', TripStatus::Completed);

        $this->journey($headers, $route->id, '2026-09-16')
            ->assertStatus(200)
            ->assertJsonPath('trip.state', 'not_started')
            ->assertJsonPath('trip.completed_at', null);
    }

    /** CARRIES WEIGHT. Every refusal is the same 404. */
    public function test_a_day_the_route_does_not_run_is_a_404(): void
    {
        [$headers, $driver] = $this->driver();
        $weekdays = $this->route($driver, 1, Recurrence::Weekdays);
        $once = $this->route($driver, 2, Recurrence::Once, date: '2026-09-21');

        // A Saturday on a weekday plan.
        $this->journey($headers, $weekdays->id, '2026-09-19')->assertStatus(404);
        // Any day but its own on a one-off journey.
        $this->journey($headers, $once->id, '2026-09-22')->assertStatus(404);
    }

    public function test_an_unknown_or_foreign_route_is_a_404(): void
    {
        [$headers] = $this->driver();
        $stranger = $this->createAccount('+905329990000');
        $theirs = $this->route($stranger, 9, Recurrence::Weekdays);

        $this->journey($headers, $theirs->id, '2026-09-16')->assertStatus(404);
        $this->journey($headers, '01991b00-0000-7000-8000-000000000099', '2026-09-16')
            ->assertStatus(404);
    }

    /**
     * A day that is not a real date answers 404 too, not 422.
     *
     * `2026-02-30` has the shape of a day and is not one. Telling it apart from
     * "not your route" would say which routes exist.
     */
    public function test_an_impossible_calendar_date_is_a_404(): void
    {
        [$headers, $driver] = $this->driver();
        $route = $this->route($driver, 1, Recurrence::Weekdays);

        foreach (['2026-02-30', '2026-13-01', '2026-00-10'] as $day) {
            $this->journey($headers, $route->id, $day)->assertStatus(404);
        }

        // And a value that is not even shaped like a day never matches the
        // route at all.
        $this->journey($headers, $route->id, 'tuesday')->assertStatus(404);
    }

    public function test_a_dated_read_requires_a_credential(): void
    {
        $this->getJson('/api/v1/routes/01991b00-0000-7000-8000-000000000001/journeys/2026-09-16')
            ->assertStatus(401);
    }

    // -------------------------------------------------------------- fixtures

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
        Recurrence $recurrence,
        ?string $date = null,
        string $timezone = 'Europe/Istanbul',
        RouteStatus $status = RouteStatus::Published,
    ): Route {
        $route = new Route;
        $route->id = sprintf('01991b00-0000-7000-8000-%012d', $n);
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

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function feed(array $headers, int $limit = 20, ?string $cursor = null): TestResponse
    {
        $query = ['limit' => $limit] + ($cursor === null ? [] : ['cursor' => $cursor]);

        return $this->getJson(self::FEED.'?'.http_build_query($query), $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function journey(array $headers, string $routeId, string $serviceDate): TestResponse
    {
        return $this->getJson("/api/v1/routes/$routeId/journeys/$serviceDate", $headers);
    }

    /**
     * The three things every feed assertion is about, and nothing else.
     *
     * @param  mixed  $body
     * @return list<array{route_id: string, service_date: string, state: string}>
     */
    private function summarise($body): array
    {
        self::assertIsArray($body);
        $journeys = $body['journeys'] ?? null;
        self::assertIsArray($journeys);

        $rows = [];
        foreach ($journeys as $journey) {
            self::assertIsArray($journey);
            /** @var array{state: string} $trip */
            $trip = $journey['trip'];
            $rows[] = [
                'route_id' => (string) $journey['route_id'],
                'service_date' => (string) $journey['service_date'],
                'state' => $trip['state'],
            ];
        }

        return $rows;
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
}
