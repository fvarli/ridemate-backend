<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Auth\TokenKind;
use App\Auth\TokenSecret;
use App\Models\AccountStatus;
use App\Models\AuthSession;
use App\Models\AuthToken;
use App\Models\SessionRevocationReason;
use App\Providers\AppServiceProvider;
use App\Support\ApiError;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * `POST /api/v1/auth/refresh` over real HTTP.
 *
 * The refusal cases dominate, and every one of them must produce the SAME
 * response. A caller holding a credential that stopped working learns only
 * that it stopped working — never whether it was unknown, wrong, expired,
 * already spent, or attached to a session somebody ended.
 */
final class RefreshEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;

    /** @var list<string> */
    protected array $tablesToTruncate = ['accounts', 'auth_sessions', 'auth_tokens', 'otp_challenges'];

    private const PATH = '/api/v1/auth/refresh';

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

    private function assertRefused(string $token, int $status = 401, string $code = ApiError::UNAUTHENTICATED): void
    {
        $this->postJson(self::PATH, ['refresh_token' => $token])
            ->assertStatus($status)
            ->assertJsonPath('error.code', $code);
    }

    // --------------------------------------------------------------- happy

    public function test_a_valid_refresh_returns_a_new_pair(): void
    {
        $first = $this->signIn(self::PHONE);

        $response = $this->postJson(self::PATH, ['refresh_token' => $first['refresh_token']]);

        $response->assertStatus(200);
        $response->assertJsonPath('token_type', 'Bearer');
        $response->assertJsonPath('session_id', $first['session_id']);

        /** @var array<string, string> $second */
        $second = $response->json();
        self::assertNotSame($first['access_token'], $second['access_token']);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);

        self::assertSame(2, AuthToken::query()->count(), 'rotation appends a generation');
        self::assertSame(1, AuthSession::query()->count(), 'and never opens a session');
    }

    public function test_the_new_access_token_works_immediately(): void
    {
        $first = $this->signIn(self::PHONE);

        /** @var array<string, string> $second */
        $second = $this->postJson(self::PATH, ['refresh_token' => $first['refresh_token']])->json();

        // Logout is the only other authenticated route in this commit, so it
        // doubles as proof the credential is accepted.
        $this->postJson('/api/v1/auth/logout', [], $this->bearer($second['access_token']))
            ->assertStatus(204);
    }

    // ------------------------------------------------------ reuse detection

    /**
     * CARRIES WEIGHT. Presenting a spent generation ends the whole session.
     */
    public function test_reusing_a_rotated_token_revokes_the_family(): void
    {
        $first = $this->signIn(self::PHONE);
        $this->postJson(self::PATH, ['refresh_token' => $first['refresh_token']])->assertStatus(200);

        $this->assertRefused($first['refresh_token']);

        $session = AuthSession::query()->findOrFail($first['session_id']);
        self::assertNotNull($session->revoked_at);
        self::assertSame(SessionRevocationReason::ReuseDetected, $session->revoked_reason);
    }

    /**
     * CARRIES WEIGHT. Detection reaches back through the retained chain.
     *
     * A design that overwrote one hash per session could only catch the
     * immediately previous generation.
     */
    public function test_reusing_a_much_older_generation_is_still_detected(): void
    {
        $pair = $this->signIn(self::PHONE);
        $original = $pair['refresh_token'];

        for ($i = 0; $i < 3; $i++) {
            /** @var array<string, string> $pair */
            $pair = $this->postJson(self::PATH, ['refresh_token' => $pair['refresh_token']])
                ->assertStatus(200)
                ->json();
        }

        $this->assertRefused($original);

        self::assertSame(
            SessionRevocationReason::ReuseDetected,
            AuthSession::query()->firstOrFail()->revoked_reason,
        );
    }

    /**
     * The documented consequence of strict detection: the newest credential
     * dies with the family, even though it was legitimately issued.
     */
    public function test_reuse_invalidates_the_newest_credential_too(): void
    {
        $first = $this->signIn(self::PHONE);
        /** @var array<string, string> $second */
        $second = $this->postJson(self::PATH, ['refresh_token' => $first['refresh_token']])->json();

        $this->assertRefused($first['refresh_token']);

        $this->assertRefused($second['refresh_token']);
        $this->postJson('/api/v1/auth/logout', [], $this->bearer($second['access_token']))
            ->assertStatus(401);
    }

    // -------------------------------------------------------- malformed input

    /**
     * CARRIES WEIGHT. A malformed credential never becomes a bound parameter.
     *
     * Query count rather than a message assertion: it shows the value was
     * rejected on shape, so caller-supplied text cannot reach the driver and
     * from there a SQL error, a stack frame or a response body.
     */
    #[DataProvider('malformedCredentials')]
    public function test_a_malformed_credential_is_refused_without_touching_the_database(string $token): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->postJson(self::PATH, ['refresh_token' => $token])
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);

        $queries = array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains(strtolower((string) $q['query']), 'auth_tokens'),
        );
        DB::disableQueryLog();

        self::assertSame([], $queries, 'a malformed credential must not reach a query');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCredentials(): array
    {
        return [
            'access prefix' => ['rma_00000000-0000-7000-8000-000000000000.secret'],
            'no prefix' => ['00000000-0000-7000-8000-000000000000.secret'],
            'no separator' => ['rmr_00000000-0000-7000-8000-000000000000'],
            'id is not a uuid' => ['rmr_not-a-uuid.secret'],
            'sql-ish id' => ["rmr_' or '1'='1.secret"],
            'empty secret' => ['rmr_00000000-0000-7000-8000-000000000000.'],
            'secret outside the alphabet' => ['rmr_00000000-0000-7000-8000-000000000000.a b'],
        ];
    }

    public function test_an_unknown_token_id_is_refused(): void
    {
        $this->assertRefused('rmr_00000000-0000-7000-8000-000000000000.'.TokenSecret::generate());
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $pair = $this->signIn(self::PHONE);
        $parsed = TokenSecret::parse(TokenKind::Refresh, $pair['refresh_token']);
        self::assertNotNull($parsed);

        $this->assertRefused(
            TokenSecret::compose(TokenKind::Refresh, $parsed->id, TokenSecret::generate()),
        );

        // A wrong secret is not reuse, so the family survives.
        self::assertNull(AuthSession::query()->firstOrFail()->revoked_at);
    }

    /**
     * CARRIES WEIGHT. An access token is not accepted here, in either place.
     */
    public function test_an_access_token_cannot_be_used_to_refresh(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->assertRefused($pair['access_token']);
    }

    /**
     * The refresh credential is body-only. Sending it as a bearer token must
     * not work, because the route has no bearer authentication at all.
     */
    public function test_the_refresh_credential_is_not_accepted_as_bearer_auth(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->postJson(self::PATH, [], $this->bearer($pair['refresh_token']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
    }

    public function test_a_missing_credential_is_a_validation_failure(): void
    {
        $this->postJson(self::PATH, [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
    }

    // ------------------------------------------------------------- lifetime

    public function test_an_expired_refresh_credential_is_refused(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->travel(config('ridemate.auth.refresh_inactivity') + 60)->seconds();

        $this->assertRefused($pair['refresh_token']);
    }

    /**
     * Past the session ceiling nothing may be exchanged. The refresh lifetime
     * is capped at the same ceiling, so both conditions arrive together — which
     * is exactly the intent: rotation cannot extend a session indefinitely.
     */
    public function test_nothing_can_be_refreshed_past_the_session_ceiling(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->travel(config('ridemate.auth.session_absolute_ttl') + 60)->seconds();

        $this->assertRefused($pair['refresh_token']);
    }

    public function test_a_revoked_session_cannot_be_refreshed(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->postJson('/api/v1/auth/logout', [], $this->bearer($pair['access_token']))
            ->assertStatus(204);

        $this->assertRefused($pair['refresh_token']);
    }

    public function test_a_suspended_account_is_forbidden(): void
    {
        $account = $this->createAccount(self::PHONE);
        $pair = $this->signIn(self::PHONE);

        $account->status = AccountStatus::Suspended;
        $account->save();

        $this->assertRefused($pair['refresh_token'], 403, ApiError::FORBIDDEN);
    }

    // --------------------------------------------------------------- limits

    public function test_the_per_address_budget_is_enforced(): void
    {
        config(['ridemate.rate_limits.refresh_per_ip_per_hour' => 1]);
        (new AppServiceProvider($this->app))->boot();

        $pair = $this->signIn(self::PHONE);

        $this->postJson(self::PATH, ['refresh_token' => $pair['refresh_token']])->assertStatus(200);

        $this->postJson(self::PATH, ['refresh_token' => 'anything'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiError::RATE_LIMITED);
    }
}
