<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\AccountStatus;
use App\Models\AuthSession;
use App\Models\AuthToken;
use App\Models\OtpChallenge;
use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * `POST /api/v1/auth/otp/verify` over real HTTP.
 *
 * This endpoint is both registration and sign-in, and the tests are arranged
 * around that: the same request produces an account when there is none and
 * authenticates when there is, without the caller ever having to say which it
 * expected.
 */
final class VerifyPasscodeEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;

    /** @var list<string> */
    protected array $tablesToTruncate = ['accounts', 'auth_sessions', 'auth_tokens', 'otp_challenges'];

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

    // --------------------------------------------------------- registration

    /**
     * CARRIES WEIGHT. A first sign-in creates exactly one of everything.
     */
    public function test_a_first_sign_in_creates_the_account_and_opens_a_session(): void
    {
        $code = $this->requestPasscode(self::PHONE);

        $response = $this->verifyPasscode(self::PHONE, $code, [
            'device_name' => 'Pixel 8',
            'platform' => 'android',
            'app_version' => '1.0.0',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'access_token', 'refresh_token', 'token_type', 'expires_in', 'session_id',
        ]);
        $response->assertJsonPath('token_type', 'Bearer');

        self::assertSame(1, Account::query()->count());

        $account = Account::query()->firstOrFail();
        self::assertSame(self::PHONE, $account->phone_e164);
        self::assertSame(AccountStatus::Active, $account->status);

        // Stamped at verification, not defaulted by the schema: possession was
        // proven just now, and this is the moment the application knew it.
        self::assertEqualsWithDelta(
            CarbonImmutable::now()->getTimestamp(),
            $account->phone_verified_at->getTimestamp(),
            5,
        );

        self::assertSame(1, AuthSession::query()->count());
        self::assertSame(1, AuthToken::query()->count());
        self::assertSame(1, AuthToken::query()->firstOrFail()->generation);

        // Device metadata is recorded for display and nothing else.
        self::assertSame('Pixel 8', AuthSession::query()->firstOrFail()->device_name);
    }

    /**
     * The response body carries no token-family bookkeeping.
     */
    public function test_the_response_exposes_no_internal_identifiers(): void
    {
        $pair = $this->signIn(self::PHONE);

        self::assertSame([
            'access_token', 'refresh_token', 'token_type', 'expires_in', 'session_id',
        ], array_keys($pair));

        $token = AuthToken::query()->firstOrFail();

        // The row id lives INSIDE each credential — `rma_{id}.{secret}` is the
        // documented format, and the id is a lookup key rather than a secret.
        // What must not happen is it appearing as a field of its own, so the
        // credentials are removed before scanning for it.
        $withoutCredentials = $pair;
        unset($withoutCredentials['access_token'], $withoutCredentials['refresh_token']);
        $remainder = (string) json_encode($withoutCredentials);

        self::assertStringNotContainsString($token->id, $remainder, 'the token id must not be a field');

        // Stored hashes must appear nowhere at all.
        $whole = (string) json_encode($pair);
        self::assertStringNotContainsString($token->access_token_hash, $whole);
        self::assertStringNotContainsString($token->refresh_token_hash, $whole);
    }

    /**
     * The member typed it one way to request and another to verify. Same
     * identity, because normalization happens before either reaches the domain.
     */
    public function test_the_number_may_be_written_differently_each_time(): void
    {
        $code = $this->requestPasscode('0532 123 45 67');

        $this->verifyPasscode('+90 532 123 45 67', $code)->assertStatus(200);

        self::assertSame(1, Account::query()->count());
        self::assertSame(self::PHONE, Account::query()->firstOrFail()->phone_e164);
    }

    // ------------------------------------------------------------- sign-in

    public function test_a_returning_member_does_not_get_a_second_account(): void
    {
        $existing = $this->createAccount(self::PHONE);

        $this->signIn(self::PHONE);

        self::assertSame(1, Account::query()->count());
        self::assertSame($existing->id, Account::query()->firstOrFail()->id);
        self::assertSame(1, AuthSession::query()->count());
    }

    public function test_signing_in_twice_opens_two_independent_sessions(): void
    {
        $this->createAccount(self::PHONE);

        $first = $this->signIn(self::PHONE);

        // A second passcode for the same number needs the resend cooldown to
        // have passed — the same rule a real member would meet.
        $this->travel(config('ridemate.otp.resend_cooldown') + 1)->seconds();

        $second = $this->signIn(self::PHONE);

        self::assertNotSame($first['session_id'], $second['session_id']);
        self::assertSame(2, AuthSession::query()->count());
        self::assertSame(1, Account::query()->count());
    }

    // ----------------------------------------------------------- suspended

    /**
     * CARRIES WEIGHT. A suspended member gets 403 and leaves no trace.
     */
    public function test_a_suspended_account_is_forbidden_and_nothing_is_created(): void
    {
        $account = $this->createAccount(self::PHONE, AccountStatus::Suspended);
        $code = $this->requestPasscode(self::PHONE);

        $response = $this->verifyPasscode(self::PHONE, $code);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', ApiError::FORBIDDEN);

        self::assertSame(0, AuthSession::query()->count(), 'no session may be opened');
        self::assertSame(0, AuthToken::query()->count(), 'no credential may be issued');

        // The suspension itself is untouched.
        self::assertSame(AccountStatus::Suspended, $account->refresh()->status);
        self::assertSame(1, Account::query()->count());
    }

    // ------------------------------------------------------------ refusals

    public function test_a_wrong_passcode_is_unauthenticated(): void
    {
        $code = $this->requestPasscode(self::PHONE);

        $response = $this->verifyPasscode(self::PHONE, $code === '000000' ? '111111' : '000000');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
        self::assertSame(0, Account::query()->count());
    }

    public function test_an_expired_passcode_is_unauthenticated(): void
    {
        $code = $this->requestPasscode(self::PHONE);

        $this->travel(config('ridemate.otp.ttl') + 1)->seconds();

        $this->verifyPasscode(self::PHONE, $code)
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
    }

    public function test_a_passcode_cannot_be_used_twice(): void
    {
        $code = $this->requestPasscode(self::PHONE);

        $this->verifyPasscode(self::PHONE, $code)->assertStatus(200);
        $this->verifyPasscode(self::PHONE, $code)->assertStatus(401);

        self::assertSame(1, AuthSession::query()->count(), 'the replay must not open a session');
    }

    /**
     * The attempt cap established by OtpService still holds over HTTP, and the
     * counter stops at the cap rather than climbing.
     */
    public function test_the_attempt_cap_is_preserved_over_http(): void
    {
        $max = (int) config('ridemate.otp.max_attempts');
        $code = $this->requestPasscode(self::PHONE);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < $max; $i++) {
            $this->verifyPasscode(self::PHONE, $wrong)->assertStatus(401);
        }

        self::assertSame($max, OtpChallenge::query()->firstOrFail()->attempts);

        // Exhausted: even the right passcode is refused now, indistinguishably.
        $this->verifyPasscode(self::PHONE, $code)
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);

        self::assertSame($max, OtpChallenge::query()->firstOrFail()->attempts);
        self::assertSame(0, Account::query()->count());
    }

    public function test_verifying_a_number_with_no_outstanding_passcode_is_unauthenticated(): void
    {
        $this->verifyPasscode(self::PHONE, '123456')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('malformedPayloads')]
    public function test_a_malformed_request_is_a_validation_failure(array $payload): void
    {
        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'no code' => [['phone' => self::PHONE]],
            'no phone' => [['code' => '123456']],
            'short code' => [['phone' => self::PHONE, 'code' => '123']],
            'long code' => [['phone' => self::PHONE, 'code' => '1234567']],
            'letters in code' => [['phone' => self::PHONE, 'code' => 'abcdef']],
            'unusable phone' => [['phone' => 'nope', 'code' => '123456']],
            'oversized device name' => [
                ['phone' => self::PHONE, 'code' => '123456', 'device_name' => str_repeat('x', 65)],
            ],
        ];
    }

    /**
     * A malformed body must not cost an attempt: validation happens before the
     * passcode is ever consulted.
     */
    public function test_a_malformed_request_does_not_consume_an_attempt(): void
    {
        $this->requestPasscode(self::PHONE);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => 'abcdef'])
            ->assertStatus(422);

        self::assertSame(0, OtpChallenge::query()->firstOrFail()->attempts);
    }
}
