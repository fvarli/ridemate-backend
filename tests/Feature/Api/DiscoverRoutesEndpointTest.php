<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Place;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteCursor;
use App\Routes\RouteDeparture;
use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contract\ValidatesTheContract;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * `GET /api/v1/routes/discover` over real HTTP.
 *
 * The query itself is proved in RouteDiscoveryTest. What is proved here is the
 * contract around it: exactly which fields cross the wire, exactly what a
 * request may say, and that a page still fills itself once a controller and a
 * cursor round-trip are in the way.
 */
final class DiscoverRoutesEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;
    use ValidatesTheContract;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'routes', 'profiles', 'accounts', 'auth_sessions', 'auth_tokens', 'otp_challenges',
    ];

    private const PATH = '/api/v1/routes/discover';

    private const SEARCHER_PHONE = '+905321234567';

    private const DRIVER_PHONE = '+905329876543';

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

    // ------------------------------------------------------------- fixtures

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    private function timezone(): string
    {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return $timezone;
    }

    /** @return array<string, string> */
    private function credential(): array
    {
        return $this->bearer($this->signIn(self::SEARCHER_PHONE)['access_token']);
    }

    /** The signed-in searcher, named so discovery will consider them a member. */
    private function searcher(): Account
    {
        $account = Account::query()->where('phone_e164', self::SEARCHER_PHONE)->sole();
        (new SaveProfile)($account, DisplayName::fromInput('Ali Can'));

        return $account;
    }

    private function driverNamed(string $name, string $phone = self::DRIVER_PHONE): Account
    {
        $account = $this->createAccount($phone);
        (new SaveProfile)($account, DisplayName::fromInput($name));

        return $account;
    }

    private function publish(
        Account $driver,
        string $id,
        string $originSlug = 'kadikoy-iskele',
        string $destinationSlug = 'levent-metro',
        ?CarbonImmutable $now = null,
    ): void {
        app(PublishRoute::class)(
            $driver,
            $id,
            $this->place($originSlug),
            $this->place($destinationSlug),
            RouteDeparture::fromInput(Recurrence::Weekdays, null, '08:00', $this->timezone()),
            3,
            new RideRules(noSmoking: true, musicOk: false, noPets: false, quiet: false),
            $now,
        );
    }

    private function id(string $tail): string
    {
        return '01991d00-0000-7000-8000-0000000000'.$tail;
    }

    /** @return array<string, string> */
    private function between(): array
    {
        return [
            'origin_place_id' => $this->place('kadikoy-iskele')->id,
            'destination_place_id' => $this->place('levent-metro')->id,
        ];
    }

    // ------------------------------------------------------------------ happy

    public function test_a_journey_between_the_requested_places_is_returned(): void
    {
        $credential = $this->credential();
        $this->searcher();
        $this->publish($this->driverNamed('İrem Yılmaz'), $this->id('01'));

        $response = $this->getJson(self::PATH.'?'.http_build_query($this->between()), $credential);

        $response->assertStatus(200);
        $response->assertHeader('X-Request-Id');
        $response->assertJsonPath('routes.0.id', $this->id('01'));
        $response->assertJsonPath('routes.0.driver.display_name', 'İrem Yılmaz');
        // Server-derived, with Turkish casing — never `IY`.
        $response->assertJsonPath('routes.0.driver.initials', 'İY');
        $response->assertJsonPath('next_cursor', null);

        $this->assertMatchesOperation($response, self::PATH, 'get');
    }

    public function test_an_empty_result_is_a_page_with_no_cursor(): void
    {
        $credential = $this->credential();
        $this->searcher();

        $response = $this->getJson(self::PATH.'?'.http_build_query($this->between()), $credential);

        $response->assertStatus(200);
        $response->assertExactJson(['routes' => [], 'next_cursor' => null]);
        $this->assertMatchesOperation($response, self::PATH, 'get');
    }

    // ------------------------------------------------------------ projection

    /**
     * CARRIES WEIGHT. An exact key set, at both levels.
     *
     * A denylist passes whatever a future change adds. This asserts the whole
     * shape, so a new field reaches a stranger only when somebody edits this.
     */
    public function test_a_result_carries_exactly_the_documented_fields(): void
    {
        $credential = $this->credential();
        $this->searcher();
        $this->publish($this->driverNamed('İrem Yılmaz'), $this->id('02'));

        /** @var array{routes: list<array<string, mixed>>} $body */
        $body = $this->getJson(
            self::PATH.'?'.http_build_query($this->between()),
            $credential,
        )->json();

        self::assertSame(['routes', 'next_cursor'], array_keys($body));
        self::assertSame([
            'id', 'origin', 'destination', 'recurrence', 'departure_date',
            'departure_time', 'timezone', 'departure_state', 'seats_offered',
            'rules', 'driver',
        ], array_keys($body['routes'][0]));

        /** @var array<string, mixed> $driver */
        $driver = $body['routes'][0]['driver'];
        self::assertSame(['display_name', 'initials'], array_keys($driver));

        /** @var array<string, mixed> $origin */
        $origin = $body['routes'][0]['origin'];
        self::assertSame(['id', 'label'], array_keys($origin));
    }

    /**
     * CARRIES WEIGHT. Nothing internal, and nothing the product does not have.
     */
    public function test_no_internal_or_invented_value_reaches_the_wire(): void
    {
        $credential = $this->credential();
        $searcher = $this->searcher();
        $driver = $this->driverNamed('İrem Yılmaz');
        $this->publish($driver, $this->id('03'));

        $raw = $this->getJson(
            self::PATH.'?'.http_build_query($this->between()),
            $credential,
        )->getContent();
        self::assertIsString($raw);

        foreach ([
            'the driver account id' => $driver->id,
            'the searcher account id' => $searcher->id,
            'the driver phone' => self::DRIVER_PHONE,
            'the profile id' => $driver->profile()->sole()->id,
        ] as $what => $value) {
            self::assertStringNotContainsString($value, $raw, "$what leaked");
        }

        foreach ([
            'account_id', 'profile_id', 'phone', 'phone_e164', 'created_at',
            'updated_at', 'published_at', 'cancelled_at', 'status', 'latitude',
            'longitude', 'slug', 'rating', 'is_verified', 'verified',
            'trip_count', 'shared_route_count', 'trust_score', 'approval_rate',
            'compatibility', 'walk_minutes', 'savings', 'cost', 'fare', 'price',
            'seats_available', 'available_seats',
        ] as $key) {
            self::assertStringNotContainsString('"'.$key.'"', $raw, "$key leaked");
        }
    }

    // ------------------------------------------------------------ eligibility

    public function test_the_callers_own_route_is_not_returned(): void
    {
        $credential = $this->credential();
        $searcher = $this->searcher();
        $this->publish($searcher, $this->id('04'));

        $this->getJson(self::PATH.'?'.http_build_query($this->between()), $credential)
            ->assertStatus(200)
            ->assertJsonPath('routes', []);
    }

    public function test_the_reverse_direction_is_not_returned(): void
    {
        $credential = $this->credential();
        $this->searcher();
        $this->publish($this->driverNamed('İrem Yılmaz'), $this->id('05'), 'levent-metro', 'kadikoy-iskele');

        $this->getJson(self::PATH.'?'.http_build_query($this->between()), $credential)
            ->assertStatus(200)
            ->assertJsonPath('routes', []);
    }

    // ------------------------------------------------------------ pagination

    /**
     * CARRIES WEIGHT. B1.1's page-filling survives the controller and a real
     * cursor round-trip.
     */
    public function test_paging_returns_every_route_once_and_ends_with_a_null_cursor(): void
    {
        $credential = $this->credential();
        $this->searcher();
        $driver = $this->driverNamed('İrem Yılmaz');

        $base = CarbonImmutable::parse('2026-09-10 09:00', $this->timezone());
        $expected = [];
        foreach (['11', '12', '13', '14', '15'] as $offset => $tail) {
            CarbonImmutable::setTestNow($base->addSeconds($offset));
            $this->publish($driver, $this->id($tail), now: $base->addSeconds($offset));
            array_unshift($expected, $this->id($tail));
        }
        CarbonImmutable::setTestNow();

        $seen = [];
        $query = $this->between() + ['limit' => 2];
        $pages = 0;

        do {
            $response = $this->getJson(self::PATH.'?'.http_build_query($query), $credential);
            $response->assertStatus(200);
            $this->assertMatchesOperation($response, self::PATH, 'get');

            /** @var array{routes: list<array<string, mixed>>, next_cursor: string|null} $body */
            $body = $response->json();

            foreach ($body['routes'] as $route) {
                $seen[] = $route['id'];
            }

            $query = $this->between() + ['limit' => 2];
            if ($body['next_cursor'] !== null) {
                $query['cursor'] = $body['next_cursor'];
            }

            $pages++;
            self::assertLessThan(10, $pages, 'paging must terminate');
        } while ($body['next_cursor'] !== null);

        self::assertSame($expected, $seen);
        self::assertSame(count($seen), count(array_unique($seen)));
    }

    // -------------------------------------------------------------- refusals

    public function test_a_credential_is_required(): void
    {
        $this->getJson(self::PATH.'?'.http_build_query($this->between()))->assertStatus(401);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function badRequests(): array
    {
        return [
            'no places at all' => [[], 'origin_place_id'],
            'no destination' => [['origin_place_id' => 'K'], 'destination_place_id'],
            'a malformed origin uuid' => [
                ['origin_place_id' => 'not-a-uuid', 'destination_place_id' => 'L'],
                'origin_place_id',
            ],
            'an origin that is not a place' => [
                ['origin_place_id' => '00000000-0000-7000-8000-00000000dead', 'destination_place_id' => 'L'],
                'origin_place_id',
            ],
            'a destination that is not a place' => [
                ['origin_place_id' => 'K', 'destination_place_id' => '00000000-0000-7000-8000-00000000dead'],
                'destination_place_id',
            ],
            'the same place twice' => [
                ['origin_place_id' => 'K', 'destination_place_id' => 'K'],
                'destination_place_id',
            ],
            'a limit of zero' => [
                ['origin_place_id' => 'K', 'destination_place_id' => 'L', 'limit' => '0'],
                'limit',
            ],
            'a limit past the maximum' => [
                ['origin_place_id' => 'K', 'destination_place_id' => 'L', 'limit' => '51'],
                'limit',
            ],
            'a cursor that is not one' => [
                ['origin_place_id' => 'K', 'destination_place_id' => 'L', 'cursor' => 'nonsense'],
                'cursor',
            ],
            'a filter nobody implemented' => [
                ['origin_place_id' => 'K', 'destination_place_id' => 'L', 'radius' => '2000'],
                'radius',
            ],
            'a sort nobody implemented' => [
                ['origin_place_id' => 'K', 'destination_place_id' => 'L', 'sort' => 'cheapest'],
                'sort',
            ],
            'seats, which would imply availability' => [
                ['origin_place_id' => 'K', 'destination_place_id' => 'L', 'seats' => '2'],
                'seats',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('badRequests')]
    public function test_an_unacceptable_request_is_a_validation_failure(
        array $query,
        string $field,
    ): void {
        $credential = $this->credential();
        $this->searcher();

        // `K` and `L` stand in for real place ids the provider cannot know.
        $resolved = [];
        foreach ($query as $key => $value) {
            $resolved[$key] = match ($value) {
                'K' => $this->place('kadikoy-iskele')->id,
                'L' => $this->place('levent-metro')->id,
                default => $value,
            };
        }

        $response = $this->getJson(self::PATH.'?'.http_build_query($resolved), $credential);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
        $response->assertJsonStructure(['error' => ['details' => [$field]]]);
        $response->assertHeader('X-Request-Id');
        $this->assertMatchesOperation($response, self::PATH, 'get');
    }

    /**
     * CARRIES WEIGHT. A cursor belongs to the list that issued it.
     *
     * The two order the same tuple over completely different rows, so resuming
     * discovery from a My Routes position would land somewhere arithmetically
     * valid and meaningless.
     */
    public function test_a_my_routes_cursor_is_refused(): void
    {
        $credential = $this->credential();
        $this->searcher();

        $foreign = (new RouteCursor(
            CarbonImmutable::now(),
            $this->id('99'),
            RouteCursor::MY_ROUTES,
        ))->encode();

        $response = $this->getJson(
            self::PATH.'?'.http_build_query($this->between() + ['cursor' => $foreign]),
            $credential,
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
        $response->assertJsonStructure(['error' => ['details' => ['cursor']]]);
    }

    /** And the reverse: a discovery cursor is refused by My Routes. */
    public function test_a_discovery_cursor_is_refused_by_my_routes(): void
    {
        $credential = $this->credential();
        $this->searcher();

        $discovery = (new RouteCursor(
            CarbonImmutable::now(),
            $this->id('98'),
            RouteCursor::DISCOVERY,
        ))->encode();

        $this->getJson('/api/v1/me/routes?cursor='.urlencode($discovery), $credential)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['cursor']]]);
    }

    // ---------------------------------------------------------------- routing

    /**
     * CARRIES WEIGHT. `discover` is a literal segment, not a route id.
     *
     * The parameterised cancel route constrains its id to a UUIDv7 precisely so
     * a literal like this cannot be swallowed by it. Dropping that constraint
     * would make `/routes/discover/cancel` resolvable and, more importantly,
     * would let any future `GET /routes/{routeId}` capture this endpoint.
     */
    public function test_the_discover_path_is_not_captured_by_a_route_id(): void
    {
        $credential = $this->credential();
        $this->searcher();

        // The literal resolves to discovery, not to a parameterised route.
        $this->getJson(self::PATH.'?'.http_build_query($this->between()), $credential)
            ->assertStatus(200);

        // And the parameterised route still refuses a non-UUIDv7 id, which is
        // the constraint that keeps the two apart.
        $this->postJson('/api/v1/routes/discover/cancel', [], $credential)->assertStatus(404);
        $this->postJson('/api/v1/routes/not-a-uuid/cancel', [], $credential)->assertStatus(404);
        // A v4 is a UUID and still not a route id here.
        $this->postJson(
            '/api/v1/routes/00000000-0000-4000-8000-000000000001/cancel',
            [],
            $credential,
        )->assertStatus(404);
    }
}
