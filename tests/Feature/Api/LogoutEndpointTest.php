<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AuthSession;
use App\Models\SessionRevocationReason;
use App\Support\ApiError;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * `POST /api/v1/auth/logout` over real HTTP.
 *
 * One write ends a session, and the interesting assertions are about REACH:
 * both credentials stop working, every retained generation stops working, and
 * nothing belonging to another device is touched.
 */
final class LogoutEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;

    /** @var list<string> */
    protected array $tablesToTruncate = ['accounts', 'auth_sessions', 'auth_tokens', 'otp_challenges'];

    private const PATH = '/api/v1/auth/logout';

    private const PHONE = '+905321234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTestSmsSender();
    }

    protected function tearDown(): void
    {
        // Before parent::tearDown(), which destroys the application.
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    public function test_signing_out_returns_an_empty_no_content_response(): void
    {
        $pair = $this->signIn(self::PHONE);

        $response = $this->postJson(self::PATH, [], $this->bearer($pair['access_token']));

        $response->assertStatus(204);
        self::assertSame('', $response->getContent(), 'a 204 must carry no body at all');
    }

    public function test_signing_out_revokes_the_session_with_the_right_reason(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->postJson(self::PATH, [], $this->bearer($pair['access_token']))->assertStatus(204);

        $session = AuthSession::query()->findOrFail($pair['session_id']);
        self::assertNotNull($session->revoked_at);
        self::assertSame(SessionRevocationReason::Logout, $session->revoked_reason);
    }

    /**
     * CARRIES WEIGHT. The access token dies before its own expiry.
     *
     * This is why revocation lives on the session rather than the token: the
     * credential is still inside its fifteen minutes and must stop working
     * anyway.
     */
    public function test_the_access_token_stops_working_immediately(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->postJson(self::PATH, [], $this->bearer($pair['access_token']))->assertStatus(204);

        $this->postJson(self::PATH, [], $this->bearer($pair['access_token']))
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
    }

    /**
     * CARRIES WEIGHT. Every generation of the family dies, not just the newest.
     */
    public function test_every_refresh_generation_becomes_unusable(): void
    {
        $first = $this->signIn(self::PHONE);

        /** @var array<string, string> $second */
        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertStatus(200)
            ->json();

        $this->postJson(self::PATH, [], $this->bearer($second['access_token']))->assertStatus(204);

        // The current generation.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])
            ->assertStatus(401);

        // And the spent one, which must not resurrect the session either.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertStatus(401);

        self::assertNotNull(AuthSession::query()->firstOrFail()->revoked_at);
    }

    /**
     * Each device has its own session. Signing out of one says nothing about
     * the others.
     */
    public function test_other_sessions_are_untouched(): void
    {
        $this->createAccount(self::PHONE);

        $phone = $this->signIn(self::PHONE);
        $this->travel(config('ridemate.otp.resend_cooldown') + 1)->seconds();
        $tablet = $this->signIn(self::PHONE);

        $this->postJson(self::PATH, [], $this->bearer($phone['access_token']))->assertStatus(204);

        $this->postJson(self::PATH, [], $this->bearer($tablet['access_token']))->assertStatus(204);
    }

    #[DataProvider('rejectedCredentials')]
    public function test_an_unusable_credential_is_unauthenticated(?string $header): void
    {
        $headers = $header === null ? [] : ['Authorization' => $header];

        $this->postJson(self::PATH, [], $headers)
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function rejectedCredentials(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'scheme only' => ['Bearer '],
            'no scheme' => ['rma_00000000-0000-7000-8000-000000000000.secret'],
            'wrong scheme' => ['Basic dXNlcjpwYXNz'],
            'lowercase scheme' => ['bearer rma_00000000-0000-7000-8000-000000000000.secret'],
            'refresh prefix' => ['Bearer rmr_00000000-0000-7000-8000-000000000000.secret'],
            'id is not a uuid' => ['Bearer rma_not-a-uuid.secret'],
            'unknown id' => ['Bearer rma_00000000-0000-7000-8000-000000000000.secret'],
        ];
    }

    public function test_an_expired_access_token_is_unauthenticated(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->travel(config('ridemate.auth.access_ttl') + 60)->seconds();

        $this->postJson(self::PATH, [], $this->bearer($pair['access_token']))
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
    }

    /**
     * Logout carries no throttle, deliberately: it is the action a member
     * takes when something is wrong, and rate-limiting it would mean refusing
     * to let someone end a session they are worried about.
     */
    public function test_logout_is_not_rate_limited(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(static fn ($r): bool => $r->uri() === 'api/v1/auth/logout');

        self::assertNotNull($route);
        self::assertSame(['api', 'auth.token'], $route->middleware());
    }
}
