<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Auth\TokenKind;
use App\Auth\TokenSecret;
use App\Models\Account;
use App\Models\AccountStatus;
use App\Support\ApiError;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * `GET /api/v1/me` over real HTTP.
 *
 * The endpoint is four lines, so the tests are mostly about what it does NOT
 * do: it adds no field the contract lacks, and it asks the database nothing
 * that authentication has not already asked.
 *
 * Its refusal matrix is inherited rather than implemented — every 401 branch
 * and the 403 come from the shared guard — but it is re-proved here anyway.
 * Inheriting a guarantee is not the same as having one, and a future route
 * that forgot the middleware would look exactly like this one until something
 * checked.
 */
final class MeEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;

    /** @var list<string> */
    protected array $tablesToTruncate = ['accounts', 'auth_sessions', 'auth_tokens', 'otp_challenges'];

    private const PATH = '/api/v1/me';

    private const PHONE = '+905321234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTestSmsSender();
    }

    protected function tearDown(): void
    {
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ----------------------------------------------------------------- happy

    public function test_it_returns_the_callers_own_account(): void
    {
        $pair = $this->signIn(self::PHONE);
        $account = Account::query()->firstOrFail();

        $response = $this->getJson(self::PATH, $this->bearer($pair['access_token']));

        $response->assertStatus(200);
        $response->assertJsonPath('account.id', $account->id);
        $response->assertJsonPath('account.phone_e164', self::PHONE);
        $response->assertJsonPath('account.status', 'active');
    }

    /**
     * CARRIES WEIGHT. Exactly five fields, and exactly one envelope key.
     *
     * Asserted as an equality on the key lists rather than a set of
     * assertJsonPath calls, because those only prove what IS there. A sixth
     * field would pass every one of them.
     */
    public function test_the_response_carries_exactly_the_contract_fields(): void
    {
        $pair = $this->signIn(self::PHONE);

        /** @var array{account: array<string, mixed>} $body */
        $body = $this->getJson(self::PATH, $this->bearer($pair['access_token']))
            ->assertStatus(200)
            ->json();

        self::assertSame(['account'], array_keys($body));
        self::assertSame([
            'id',
            'phone_e164',
            'phone_verified_at',
            'status',
            'created_at',
        ], array_keys($body['account']));
    }

    /**
     * The specific leak a model serialization would produce.
     *
     * `updated_at` exists on the row, is not in the contract, and is exactly
     * what `$account->toArray()` would add for free.
     */
    public function test_no_model_field_leaks_into_the_response(): void
    {
        $pair = $this->signIn(self::PHONE);

        $body = (string) $this->getJson(self::PATH, $this->bearer($pair['access_token']))
            ->assertStatus(200)
            ->getContent();

        foreach ([
            'updated_at', 'session_id', 'token', 'generation', 'device_name',
            'platform', 'app_version', 'profile', 'display_name', 'email',
            'verification', 'trust', 'role', 'permission', 'revoked',
        ] as $absent) {
            self::assertStringNotContainsString($absent, $body, "'$absent' leaked into /me");
        }
    }

    /**
     * The response says nothing about the credential that authorised it.
     */
    public function test_the_response_contains_no_credential_material(): void
    {
        $pair = $this->signIn(self::PHONE);

        $body = (string) $this->getJson(self::PATH, $this->bearer($pair['access_token']))
            ->assertStatus(200)
            ->getContent();

        self::assertStringNotContainsString($pair['access_token'], $body);
        self::assertStringNotContainsString($pair['refresh_token'], $body);
        self::assertStringNotContainsString($pair['session_id'], $body);
    }

    // ------------------------------------------------------------- queries

    /**
     * CARRIES WEIGHT. The controller adds no query of its own.
     *
     * Authentication eager-loads `session.account`, so the account is already
     * in memory. A controller that re-fetched it — or a lazy relation touched
     * during serialization — would double the database work of every
     * authenticated request and, worse, would be a second place deciding who
     * the caller is.
     *
     * Counted by table rather than in total, so the assertion keeps meaning if
     * the authentication path itself changes shape.
     */
    public function test_serving_the_account_costs_no_extra_query(): void
    {
        $pair = $this->signIn(self::PHONE);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson(self::PATH, $this->bearer($pair['access_token']))->assertStatus(200);

        $queries = array_map(
            static fn (array $q): string => strtolower((string) $q['query']),
            DB::getQueryLog(),
        );
        DB::disableQueryLog();

        $accountQueries = array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "accounts"'),
        );

        self::assertCount(
            1,
            $accountQueries,
            'the account should be read once, by authentication, and not again',
        );

        // And the whole request stays within the three reads authentication
        // already needs: the token, its session, and the account.
        self::assertLessThanOrEqual(3, count($queries), 'unexpected extra query work');
    }

    // ------------------------------------------------------------- refusals

    #[DataProvider('unusableCredentials')]
    public function test_an_unusable_credential_is_unauthenticated(?string $header): void
    {
        $headers = $header === null ? [] : ['Authorization' => $header];

        $this->getJson(self::PATH, $headers)
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusableCredentials(): array
    {
        return [
            'missing header' => [null],
            'empty header' => [''],
            'scheme only' => ['Bearer '],
            'no scheme' => ['rma_00000000-0000-7000-8000-000000000000.secret'],
            'wrong scheme' => ['Basic dXNlcjpwYXNz'],
            'lowercase scheme' => ['bearer rma_00000000-0000-7000-8000-000000000000.secret'],
            'refresh prefix' => ['Bearer rmr_00000000-0000-7000-8000-000000000000.secret'],
            'no separator' => ['Bearer rma_00000000-0000-7000-8000-000000000000'],
            'malformed uuid' => ['Bearer rma_not-a-uuid.secret'],
            'sql-ish id' => ["Bearer rma_' or '1'='1.secret"],
            'unknown id' => ['Bearer rma_00000000-0000-7000-8000-000000000000.secret'],
        ];
    }

    /**
     * CARRIES WEIGHT. A malformed identifier never becomes a bound parameter.
     */
    public function test_a_malformed_identifier_short_circuits_before_any_lookup(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson(self::PATH, $this->bearer('rma_not-a-uuid.secret'))
            ->assertStatus(401);

        $authQueries = array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains(strtolower((string) $q['query']), 'auth_tokens'),
        );
        DB::disableQueryLog();

        self::assertSame([], $authQueries, 'a malformed identifier must not reach a query');
    }

    /**
     * A genuine refresh credential is still not an access credential. The
     * prefix would reject it; so would the hash domain if the prefix did not.
     */
    public function test_a_refresh_credential_does_not_authenticate(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->getJson(self::PATH, $this->bearer($pair['refresh_token']))
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
    }

    public function test_a_wrong_secret_is_unauthenticated(): void
    {
        $pair = $this->signIn(self::PHONE);
        $parsed = TokenSecret::parse(TokenKind::Access, $pair['access_token']);
        self::assertNotNull($parsed);

        $forged = TokenSecret::compose(TokenKind::Access, $parsed->id, TokenSecret::generate());

        $this->getJson(self::PATH, $this->bearer($forged))->assertStatus(401);
    }

    public function test_an_expired_access_token_is_unauthenticated(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->travel(config('ridemate.auth.access_ttl') + 60)->seconds();

        $this->getJson(self::PATH, $this->bearer($pair['access_token']))->assertStatus(401);
    }

    public function test_a_revoked_session_is_unauthenticated(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->postJson('/api/v1/auth/logout', [], $this->bearer($pair['access_token']))
            ->assertStatus(204);

        $this->getJson(self::PATH, $this->bearer($pair['access_token']))
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
    }

    public function test_a_session_past_its_ceiling_is_unauthenticated(): void
    {
        $pair = $this->signIn(self::PHONE);

        $this->travel(config('ridemate.auth.session_absolute_ttl') + 60)->seconds();

        $this->getJson(self::PATH, $this->bearer($pair['access_token']))->assertStatus(401);
    }

    /**
     * CARRIES WEIGHT. Suspension is 403, never 401.
     *
     * A 401 would send the client round the sign-in loop forever, and signing
     * in is exactly what a suspended member cannot do.
     */
    public function test_a_suspended_account_is_forbidden(): void
    {
        $account = $this->createAccount(self::PHONE);
        $pair = $this->signIn(self::PHONE);

        $account->status = AccountStatus::Suspended;
        $account->save();

        $this->getJson(self::PATH, $this->bearer($pair['access_token']))
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiError::FORBIDDEN);
    }

    /**
     * Two members, two accounts, and neither can see the other's.
     */
    public function test_a_credential_only_ever_returns_its_own_account(): void
    {
        $mine = $this->createAccount(self::PHONE);
        $theirs = $this->createAccount('+905329876543');

        $pair = $this->signIn(self::PHONE);

        $this->getJson(self::PATH, $this->bearer($pair['access_token']))
            ->assertStatus(200)
            ->assertJsonPath('account.id', $mine->id)
            ->assertJsonPath('account.phone_e164', self::PHONE);

        self::assertNotSame($mine->id, $theirs->id);
    }
}
