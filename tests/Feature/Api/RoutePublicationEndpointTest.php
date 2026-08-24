<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\AccountStatus;
use App\Models\Place;
use App\Models\Route;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\Contract\ValidatesTheContract;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * Publishing a journey over HTTP, and everything the boundary refuses.
 */
final class RoutePublicationEndpointTest extends TestCase
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
        'routes',
    ];

    private const ROUTE_ID = '01991b00-0000-7000-8000-0000000000a1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTestSmsSender();
        $this->seed(PilotPlaceSeeder::class);
    }

    protected function tearDown(): void
    {
        // Before parent::tearDown(), which destroys the application.
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => self::ROUTE_ID,
            'origin_place_id' => $this->place('kadikoy-iskele')->id,
            'destination_place_id' => $this->place('levent-metro')->id,
            'recurrence' => 'weekdays',
            'departure_time' => '08:25',
            'seats_offered' => 3,
            'rules' => [
                'no_smoking' => true,
                'music_ok' => false,
                'no_pets' => false,
                'quiet' => false,
            ],
        ], $overrides);
    }

    // ------------------------------------------------------------- the guard

    public function test_publishing_requires_a_credential(): void
    {
        $this->postJson('/api/v1/routes', $this->payload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_the_catalogue_requires_a_credential(): void
    {
        $this->getJson('/api/v1/places')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_suspended_member_may_not_publish(): void
    {
        $tokens = $this->signIn();
        $this->createAccount('+905321112233')->fresh();

        Account::query()->update(['status' => AccountStatus::Suspended]);

        $this->postJson('/api/v1/routes', $this->payload(), $this->bearer($tokens['access_token']))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    // ------------------------------------------------------------ the places

    public function test_the_catalogue_exposes_only_an_id_and_a_label(): void
    {
        $tokens = $this->signIn();

        $response = $this->getJson('/api/v1/places', $this->bearer($tokens['access_token']))
            ->assertOk();

        $this->assertMatchesOperation($response, '/api/v1/places', 'get');

        /** @var array<int, array<string, mixed>> $places */
        $places = $response->json('places');
        self::assertCount(5, $places);

        foreach ($places as $place) {
            self::assertSame(['id', 'label'], array_keys($place));
        }

        // Not just absent keys: no coordinate VALUE may appear anywhere in the
        // body, however it were spelled.
        $body = $response->getContent();
        self::assertIsString($body);

        foreach (['40.99', '29.02', '41.07', 'kadikoy-iskele', 'Europe/Istanbul', 'point'] as $secret) {
            self::assertStringNotContainsString($secret, $body, $secret);
        }
    }

    // --------------------------------------------------------- publishing

    public function test_a_first_publication_is_created(): void
    {
        $tokens = $this->signIn();

        $response = $this->postJson('/api/v1/routes', $this->payload(), $this->bearer($tokens['access_token']))
            ->assertStatus(201)
            ->assertJsonPath('route.id', self::ROUTE_ID)
            ->assertJsonPath('route.status', 'published')
            ->assertJsonPath('route.departure_state', 'upcoming')
            ->assertJsonPath('route.departure_time', '08:25')
            ->assertJsonPath('route.timezone', 'Europe/Istanbul')
            ->assertJsonPath('route.origin.label', 'Kadıköy, Vapur İskelesi');

        $this->assertMatchesOperation($response, '/api/v1/routes', 'post');
        self::assertSame(1, Route::query()->count());
    }

    /**
     * CARRIES WEIGHT. A retry is the same publication, observed again.
     */
    public function test_an_identical_retry_returns_the_same_route_and_creates_no_second_row(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        $first = $this->postJson('/api/v1/routes', $this->payload(), $headers)->assertStatus(201);

        // Time passes between the attempts, as it would over a dropped
        // connection. Server-generated fields must not be part of "the same".
        $this->travel(90)->seconds();

        $second = $this->postJson('/api/v1/routes', $this->payload(), $headers)->assertStatus(200);

        $this->assertMatchesOperation($second, '/api/v1/routes', 'post');
        self::assertSame(1, Route::query()->count());
        self::assertSame($first->json('route.id'), $second->json('route.id'));
        self::assertSame($first->json('route.published_at'), $second->json('route.published_at'));
    }

    public function test_the_same_id_with_a_different_journey_is_a_conflict(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        $this->postJson('/api/v1/routes', $this->payload(), $headers)->assertStatus(201);

        $this->postJson('/api/v1/routes', $this->payload(['seats_offered' => 4]), $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict');

        self::assertSame(1, Route::query()->count());
    }

    /**
     * CARRIES WEIGHT. A stranger reusing the id learns only that it is taken.
     */
    public function test_another_member_reusing_the_id_learns_nothing_about_it(): void
    {
        $owner = $this->signIn('+905321112233');
        $this->postJson('/api/v1/routes', $this->payload(), $this->bearer($owner['access_token']))
            ->assertStatus(201);

        $stranger = $this->signIn('+905329998877');

        $response = $this->postJson('/api/v1/routes', $this->payload(), $this->bearer($stranger['access_token']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict');

        $body = $response->getContent();
        self::assertIsString($body);

        // Nothing about the other journey: not the owner, not the endpoints,
        // not the times.
        foreach (['+9053211', 'Kadıköy', 'Levent', '08:25', 'weekdays'] as $leak) {
            self::assertStringNotContainsString($leak, $body, $leak);
        }
    }

    // --------------------------------------------------------- what is refused

    public function test_a_version_4_id_is_refused(): void
    {
        $tokens = $this->signIn();

        $this->postJson(
            '/api/v1/routes',
            $this->payload(['id' => '9f1b7f4e-6c2a-4a5e-8f3d-2b1c4d5e6f70']),
            $this->bearer($tokens['access_token']),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['id']]]);
    }

    public function test_fields_the_client_may_not_send_are_refused_rather_than_ignored(): void
    {
        $tokens = $this->signIn();

        foreach ([
            'timezone' => 'UTC',
            'account_id' => '01991a00-0000-7000-8000-000000000009',
            'latitude' => '41.0',
            'longitude' => '29.0',
            'cost_share_per_person' => 18,
            'published_at' => '2026-01-01T00:00:00Z',
        ] as $field => $value) {
            $this->postJson(
                '/api/v1/routes',
                $this->payload([$field => $value]),
                $this->bearer($tokens['access_token']),
            )
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['details' => [$field]]]);
        }

        self::assertSame(0, Route::query()->count());
    }

    public function test_an_unknown_ride_rule_is_refused(): void
    {
        $tokens = $this->signIn();
        $payload = $this->payload();
        $payload['rules']['pets_negotiable'] = true;

        $this->postJson('/api/v1/routes', $payload, $this->bearer($tokens['access_token']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_a_malformed_date_or_time_is_refused(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        foreach ([
            ['recurrence' => 'once', 'departure_date' => '2026-02-30'],
            ['recurrence' => 'once', 'departure_date' => '14-09-2026'],
            ['departure_time' => '08:00:00'],
            ['departure_time' => '8:00'],
            ['departure_time' => '25:00'],
        ] as $bad) {
            $this->postJson('/api/v1/routes', $this->payload($bad), $headers)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed');
        }
    }

    public function test_the_recurrence_truth_table_is_enforced(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        // once without a date, and weekdays carrying one.
        $this->postJson('/api/v1/routes', $this->payload(['recurrence' => 'once']), $headers)
            ->assertStatus(422);

        $this->postJson(
            '/api/v1/routes',
            $this->payload(['recurrence' => 'weekdays', 'departure_date' => '2099-01-01']),
            $headers,
        )->assertStatus(422);

        // And the two shapes that are legal.
        $this->postJson(
            '/api/v1/routes',
            $this->payload(['recurrence' => 'once', 'departure_date' => '2099-01-01']),
            $headers,
        )->assertStatus(201);
    }

    /**
     * CARRIES WEIGHT. Past in Istanbul is past, whatever UTC would have said.
     */
    public function test_a_departure_already_past_in_the_pilot_timezone_is_refused(): void
    {
        $tokens = $this->signIn();

        // 09:30 UTC is 12:30 in Istanbul, so 12:00 today has gone — though a
        // server reading the wall clock as UTC would still see it ahead.
        $this->travelTo('2026-06-15T09:30:00Z');

        $this->postJson(
            '/api/v1/routes',
            $this->payload(['recurrence' => 'once', 'departure_date' => '2026-06-15', 'departure_time' => '12:00']),
            $this->bearer($tokens['access_token']),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        self::assertSame(0, Route::query()->count());
    }

    public function test_a_journey_to_the_same_place_is_refused(): void
    {
        $tokens = $this->signIn();
        $here = $this->place('levent-metro')->id;

        $this->postJson(
            '/api/v1/routes',
            $this->payload(['origin_place_id' => $here, 'destination_place_id' => $here]),
            $this->bearer($tokens['access_token']),
        )->assertStatus(422);
    }

    public function test_an_unknown_place_is_refused(): void
    {
        $tokens = $this->signIn();

        $this->postJson(
            '/api/v1/routes',
            $this->payload(['origin_place_id' => '01991a00-0000-7000-8000-0000000000ff']),
            $this->bearer($tokens['access_token']),
        )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['origin_place_id']]]);
    }

    public function test_impossible_seat_counts_are_refused_cleanly(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        foreach ([0, -1, 32768] as $seats) {
            $this->postJson('/api/v1/routes', $this->payload(['seats_offered' => $seats]), $headers)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed');
        }
    }

    // ------------------------------------------------------------ log hygiene

    /**
     * A route's endpoints and times describe somebody's daily commute.
     */
    public function test_publishing_writes_no_location_or_payload_into_the_log(): void
    {
        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = $event->message.' '.json_encode($event->context);
        });

        $tokens = $this->signIn('+905321112233');
        $this->postJson('/api/v1/routes', $this->payload(), $this->bearer($tokens['access_token']))
            ->assertStatus(201);

        foreach ($lines as $line) {
            foreach ([
                'Kadıköy', 'Levent', '40.99', '29.02', '+905321112233',
                '08:25', 'kadikoy-iskele', self::ROUTE_ID,
            ] as $secret) {
                self::assertStringNotContainsString($secret, $line, $secret);
            }
        }
    }
}
