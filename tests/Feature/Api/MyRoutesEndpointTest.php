<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Place;
use App\Models\Route;
use App\Routes\RouteCursor;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Tests\Contract\ValidatesTheContract;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * A member's own routes: reading them, and withdrawing one.
 */
final class MyRoutesEndpointTest extends TestCase
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
    private function payload(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
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

    private function id(int $n): string
    {
        return sprintf('01991b00-0000-7000-8000-%012d', $n);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $overrides
     */
    private function publish(array $headers, int $n, array $overrides = []): string
    {
        $id = $this->id($n);

        $this->postJson('/api/v1/routes', $this->payload($id, $overrides), $headers)
            ->assertStatus(201);

        return $id;
    }

    // ------------------------------------------------------------ the listing

    public function test_listing_requires_a_credential(): void
    {
        $this->getJson('/api/v1/me/routes')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_member_with_no_routes_gets_an_empty_page(): void
    {
        $tokens = $this->signIn();

        $response = $this->getJson('/api/v1/me/routes', $this->bearer($tokens['access_token']))
            ->assertOk()
            ->assertJsonPath('routes', [])
            ->assertJsonPath('next_cursor', null);

        $this->assertMatchesOperation($response, '/api/v1/me/routes', 'get');
    }

    public function test_a_member_sees_only_their_own_routes(): void
    {
        $mine = $this->signIn('+905321112233');
        $theirs = $this->signIn('+905329998877');

        $this->publish($this->bearer($mine['access_token']), 1);
        $this->publish($this->bearer($theirs['access_token']), 2);

        $this->getJson('/api/v1/me/routes', $this->bearer($mine['access_token']))
            ->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.id', $this->id(1));
    }

    /**
     * Newest first, and the order is the server's, not the client's.
     */
    public function test_routes_come_back_newest_first(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        // Published in ascending id order at distinct instants, so "newest
        // first" and "highest id first" are the same here only because the
        // clock agrees — which is what makes the assertion meaningful.
        foreach ([1, 2, 3] as $n) {
            $this->travel(1)->seconds();
            $this->publish($headers, $n);
        }

        $this->getJson('/api/v1/me/routes', $headers)
            ->assertOk()
            ->assertJsonPath('routes.0.id', $this->id(3))
            ->assertJsonPath('routes.1.id', $this->id(2))
            ->assertJsonPath('routes.2.id', $this->id(1));
    }

    // --------------------------------------------------------- the paging

    public function test_the_default_page_holds_twenty(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        for ($n = 1; $n <= 21; $n++) {
            $this->travel(1)->seconds();
            $this->publish($headers, $n);
        }

        $this->getJson('/api/v1/me/routes', $headers)
            ->assertOk()
            ->assertJsonCount(20, 'routes')
            ->assertJsonMissingPath('next_cursor.0');

        self::assertNotNull(
            $this->getJson('/api/v1/me/routes', $headers)->json('next_cursor'),
        );
    }

    public function test_a_custom_limit_is_honoured(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        foreach ([1, 2, 3] as $n) {
            $this->travel(1)->seconds();
            $this->publish($headers, $n);
        }

        $this->getJson('/api/v1/me/routes?limit=2', $headers)
            ->assertOk()
            ->assertJsonCount(2, 'routes');
    }

    public function test_an_impossible_limit_is_refused(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        foreach (['0', '51', '-1', 'twenty', '1.5'] as $limit) {
            $this->getJson("/api/v1/me/routes?limit=$limit", $headers)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['details' => ['limit']]]);
        }
    }

    /**
     * CARRIES WEIGHT. A route published between pages must not disturb them.
     *
     * This is the whole reason for keyset rather than offset. With an offset,
     * inserting a newer row pushes everything down and page two repeats a route
     * page one already showed.
     */
    public function test_a_route_inserted_between_pages_causes_no_duplicate_and_no_skip(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        foreach ([1, 2, 3, 4] as $n) {
            $this->travel(1)->seconds();
            $this->publish($headers, $n);
        }

        $first = $this->getJson('/api/v1/me/routes?limit=2', $headers)->assertOk();
        /** @var list<string> $firstIds */
        $firstIds = $first->json('routes.*.id');
        $cursor = $first->json('next_cursor');
        self::assertIsString($cursor);

        // A newer route arrives while the member is reading.
        $this->travel(1)->seconds();
        $this->publish($headers, 99);

        $second = $this->getJson('/api/v1/me/routes?limit=2&cursor='.urlencode($cursor), $headers)
            ->assertOk();
        /** @var list<string> $secondIds */
        $secondIds = $second->json('routes.*.id');

        self::assertSame([], array_intersect($firstIds, $secondIds), 'a route appeared twice');

        // Every route that existed when page one was read is still reachable.
        $seen = array_merge($firstIds, $secondIds);
        foreach ([1, 2, 3, 4] as $n) {
            self::assertContains($this->id($n), $seen, 'a pre-existing route was skipped');
        }

        // And the newcomer is not smuggled into a later page — it belongs at
        // the front, which the member will see when they start again.
        self::assertNotContains($this->id(99), $secondIds);
    }

    // ---------------------------------------------------------- the cursor

    /**
     * CARRIES WEIGHT. "Opaque" has to be true, not merely documented.
     */
    public function test_the_cursor_reveals_nothing_and_refuses_tampering(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        foreach ([1, 2] as $n) {
            $this->travel(1)->seconds();
            $this->publish($headers, $n);
        }

        $cursor = $this->getJson('/api/v1/me/routes?limit=1', $headers)->json('next_cursor');
        self::assertIsString($cursor);

        // Nothing readable in it, plainly or through base64.
        $decoded = base64_decode($cursor, true);
        foreach ([$cursor, $decoded === false ? '' : $decoded] as $surface) {
            self::assertStringNotContainsString($this->id(2), $surface);
            self::assertStringNotContainsString('2026-', $surface);
            self::assertStringNotContainsString('rm.myroutes', $surface);
        }

        // And a cursor somebody edited is refused as a bad request, not a 500.
        foreach ([
            substr($cursor, 0, -4).'AAAA',
            base64_encode('rm.myroutes.v1|2026-01-01T00:00:00.000000+00:00|'.$this->id(2)),
            'not-a-cursor',
        ] as $tampered) {
            $this->getJson('/api/v1/me/routes?cursor='.urlencode($tampered), $headers)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['details' => ['cursor']]]);
        }
    }

    // ------------------------------------------------------ the cancellation

    public function test_a_member_cancels_their_own_upcoming_route(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);
        $id = $this->publish($headers, 1);

        $response = $this->postJson("/api/v1/routes/$id/cancel", [], $headers)
            ->assertOk()
            ->assertJsonPath('route.status', 'cancelled');

        $this->assertMatchesOperation($response, '/api/v1/routes/{routeId}/cancel', 'post');
        self::assertNotNull($response->json('route.cancelled_at'));
    }

    /**
     * CARRIES WEIGHT. Idempotent means no SECOND mutation.
     *
     * Not that the two bodies are byte-identical — `departure_state` is derived
     * and may legitimately move as the clock does. What must not move is the
     * resource: no new cancelled_at, no bumped updated_at.
     */
    public function test_cancelling_again_changes_nothing_about_the_route(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);
        $id = $this->publish($headers, 1);

        $this->postJson("/api/v1/routes/$id/cancel", [], $headers)->assertOk();

        $after = Route::query()->findOrFail($id);
        $cancelledAt = $after->cancelled_at;
        $updatedAt = $after->updated_at;

        $this->travel(120)->seconds();

        $this->postJson("/api/v1/routes/$id/cancel", [], $headers)
            ->assertOk()
            ->assertJsonPath('route.status', 'cancelled');

        $again = Route::query()->findOrFail($id);
        self::assertEquals($cancelledAt, $again->cancelled_at, 'cancelled_at moved');
        self::assertEquals($updatedAt, $again->updated_at, 'the row was written again');
    }

    /**
     * A journey that already departed cannot be withdrawn.
     */
    public function test_cancelling_a_departed_one_off_is_refused_and_changes_nothing(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);

        $this->travelTo('2026-06-15T05:00:00Z'); // 08:00 in Istanbul
        $id = $this->publish($headers, 1, [
            'recurrence' => 'once',
            'departure_date' => '2026-06-15',
            'departure_time' => '18:00',
        ]);

        // The journey happens, and the day moves on.
        $this->travelTo('2026-06-16T05:00:00Z');

        $this->postJson("/api/v1/routes/$id/cancel", [], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict');

        $route = Route::query()->findOrFail($id);
        self::assertSame('published', $route->status->value);
        self::assertNull($route->cancelled_at);
    }

    /**
     * A recurring journey always has another weekday, so it stays cancellable.
     */
    public function test_a_weekday_route_remains_cancellable_however_much_time_passes(): void
    {
        $tokens = $this->signIn('+905321112233');
        $id = $this->publish($this->bearer($tokens['access_token']), 1);

        $this->travel(400)->days();

        // A new credential, because the old one expired long ago — access
        // tokens live fifteen minutes and this is the point. What is being
        // tested is that the ROUTE is still cancellable, not that a year-old
        // token still works.
        $later = $this->signIn('+905321112233');

        $this->postJson("/api/v1/routes/$id/cancel", [], $this->bearer($later['access_token']))
            ->assertOk()
            ->assertJsonPath('route.status', 'cancelled')
            ->assertJsonPath('route.departure_state', 'upcoming');
    }

    public function test_another_members_route_is_not_found(): void
    {
        $owner = $this->signIn('+905321112233');
        $id = $this->publish($this->bearer($owner['access_token']), 1);

        $stranger = $this->signIn('+905329998877');

        $this->postJson("/api/v1/routes/$id/cancel", [], $this->bearer($stranger['access_token']))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        self::assertSame('published', Route::query()->findOrFail($id)->status->value);
    }

    public function test_an_unknown_route_is_not_found(): void
    {
        $tokens = $this->signIn();

        $this->postJson(
            '/api/v1/routes/'.$this->id(404).'/cancel',
            [],
            $this->bearer($tokens['access_token']),
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_malformed_route_id_is_not_found(): void
    {
        $tokens = $this->signIn();

        foreach ([
            'not-a-uuid',
            '9f1b7f4e-6c2a-4a5e-8f3d-2b1c4d5e6f70', // a valid v4
        ] as $id) {
            $this->postJson("/api/v1/routes/$id/cancel", [], $this->bearer($tokens['access_token']))
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'not_found');
        }
    }

    // ------------------------------------------- a cancelled id is spent

    /**
     * CARRIES WEIGHT. Re-publishing a withdrawn journey must not revive it.
     */
    public function test_republishing_a_cancelled_id_is_a_conflict(): void
    {
        $tokens = $this->signIn();
        $headers = $this->bearer($tokens['access_token']);
        $id = $this->publish($headers, 1);

        $this->postJson("/api/v1/routes/$id/cancel", [], $headers)->assertOk();
        $cancelledAt = Route::query()->findOrFail($id)->cancelled_at;

        // The same body that created it in the first place.
        $this->postJson('/api/v1/routes', $this->payload($id), $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict');

        self::assertSame(1, Route::query()->count());

        $route = Route::query()->findOrFail($id);
        self::assertSame('cancelled', $route->status->value);
        self::assertEquals($cancelledAt, $route->cancelled_at);
    }

    // ------------------------------------------------------------ hygiene

    public function test_neither_endpoint_writes_a_commute_into_the_log(): void
    {
        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = $event->message.' '.json_encode($event->context);
        });

        $tokens = $this->signIn('+905321112233');
        $headers = $this->bearer($tokens['access_token']);
        $id = $this->publish($headers, 1);

        $this->getJson('/api/v1/me/routes', $headers)->assertOk();
        $this->postJson("/api/v1/routes/$id/cancel", [], $headers)->assertOk();

        foreach ($lines as $line) {
            foreach ([
                'Kadıköy', 'Levent', '40.99', '29.02', '+905321112233',
                '08:25', 'kadikoy-iskele', $id,
            ] as $secret) {
                self::assertStringNotContainsString($secret, $line, $secret);
            }
        }
    }

    // --------------------------------------------------------- the cursor unit

    public function test_a_cursor_from_another_shape_fails_closed(): void
    {
        // A well-formed cursor of a version this code does not speak.
        $foreign = Crypt::encryptString(
            'rm.myroutes.v2|2026-01-01T00:00:00.000000+00:00|'.$this->id(1),
        );

        self::assertNull(RouteCursor::decode($foreign));
        self::assertNull(RouteCursor::decode(Crypt::encryptString('nonsense')));
    }
}
