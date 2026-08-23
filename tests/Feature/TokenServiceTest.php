<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\DeviceDescription;
use App\Auth\IssuedTokenPair;
use App\Auth\TokenKind;
use App\Auth\TokenSecret;
use App\Auth\TokenService;
use App\Models\Account;
use App\Models\AccountStatus;
use App\Models\AuthSession;
use App\Models\AuthToken;
use App\Models\SessionRevocationReason;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * The credential lifecycle.
 *
 * These are the assertions the whole token design exists to satisfy. Several
 * of them would still pass against a much weaker implementation, so the ones
 * that would NOT are marked — those are the tests carrying real weight.
 */
final class TokenServiceTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = app(TokenService::class);
    }

    private function issueFor(?Account $account = null): IssuedTokenPair
    {
        return $this->tokens->issue(
            $account ?? $this->createAccount(),
            new DeviceDescription('Pixel 8', 'android', '1.0.0'),
        );
    }

    // ---------------------------------------------------------------- issuing

    public function test_issuing_opens_a_session_and_its_first_generation(): void
    {
        $account = $this->createAccount();
        $pair = $this->issueFor($account);

        $session = AuthSession::query()->findOrFail($pair->sessionId);
        self::assertSame($account->id, $session->account_id);
        self::assertTrue($session->isLive());
        self::assertSame('Pixel 8', $session->device_name);

        self::assertSame(1, AuthToken::query()->where('session_id', $session->id)->count());
        self::assertSame(1, AuthToken::query()->firstOrFail()->generation);
    }

    public function test_an_issued_access_token_authenticates(): void
    {
        $account = $this->createAccount();
        $pair = $this->issueFor($account);

        $context = $this->tokens->authenticate($pair->accessToken);

        self::assertSame($account->id, $context->account->id);
        self::assertSame($pair->sessionId, $context->session->id);
    }

    public function test_the_access_lifetime_comes_from_configuration(): void
    {
        config(['ridemate.auth.access_ttl' => 900]);

        self::assertEqualsWithDelta(900, $this->issueFor()->expiresIn, 2);
    }

    /**
     * CARRIES WEIGHT. Only hashes reach the database.
     *
     * Scans every column of every row for the plaintext, so a future change
     * that stores a credential "temporarily" anywhere on the row fails here.
     */
    public function test_no_credential_plaintext_is_ever_stored(): void
    {
        $pair = $this->issueFor();

        foreach (DB::table('auth_tokens')->get() as $row) {
            foreach ((array) $row as $value) {
                if (! is_string($value)) {
                    continue;
                }
                self::assertStringNotContainsString($pair->accessToken, $value);
                self::assertStringNotContainsString($pair->refreshToken, $value);
            }
        }
    }

    public function test_each_generation_gets_distinct_secrets(): void
    {
        $first = $this->issueFor();
        $second = $this->tokens->rotate($first->refreshToken);

        self::assertNotSame($first->accessToken, $second->accessToken);
        self::assertNotSame($first->refreshToken, $second->refreshToken);
        self::assertNotSame($first->accessToken, $first->refreshToken);
    }

    // --------------------------------------------------------- access refusal

    /**
     * CARRIES WEIGHT. A malformed credential never becomes a bound parameter.
     *
     * Query count, not a message assertion: it proves the value was rejected
     * on shape before reaching the driver, which is what stops caller text
     * appearing in a SQL error, a stack frame or a 500 body.
     */
    #[DataProvider('malformedCredentials')]
    public function test_a_malformed_credential_is_refused_without_a_query(string $credential): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->tokens->authenticate($credential);
            self::fail('the credential should have been refused');
        } catch (AuthenticationException) {
            self::assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCredentials(): array
    {
        return [
            'empty' => [''],
            'no prefix' => ['0198a1b2-c3d4-7000-8000-000000000000.secret'],
            'wrong prefix' => ['rmr_0198a1b2-c3d4-7000-8000-000000000000.secret'],
            'no separator' => ['rma_0198a1b2-c3d4-7000-8000-000000000000'],
            'two separators' => ['rma_0198a1b2-c3d4-7000-8000-000000000000.a.b'],
            'id is not a uuid' => ['rma_not-a-uuid.secret'],
            'sql-ish id' => ["rma_' or '1'='1.secret"],
            'empty secret' => ['rma_0198a1b2-c3d4-7000-8000-000000000000.'],
            'secret outside the alphabet' => ['rma_0198a1b2-c3d4-7000-8000-000000000000.a b'],
        ];
    }

    /**
     * CARRIES WEIGHT. Domain separation, proven rather than asserted.
     *
     * The refresh secret is re-composed under the ACCESS prefix, so the prefix
     * check cannot be what rejects it. It fails because the stored hash was
     * computed under a different domain — which is the guarantee, rather than
     * the convenience.
     */
    public function test_a_refresh_secret_cannot_authenticate_as_an_access_token(): void
    {
        $pair = $this->issueFor();

        $parsed = TokenSecret::parse(TokenKind::Refresh, $pair->refreshToken);
        self::assertNotNull($parsed);

        $disguised = TokenSecret::compose(TokenKind::Access, $parsed->id, $parsed->secret);

        $this->expectException(AuthenticationException::class);
        $this->tokens->authenticate($disguised);
    }

    public function test_an_unknown_token_id_is_refused(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->tokens->authenticate('rma_0198a1b2-c3d4-7000-8000-000000000000.'.TokenSecret::generate());
    }

    public function test_an_expired_access_token_is_refused(): void
    {
        $pair = $this->issueFor();

        $this->travel(config('ridemate.auth.access_ttl') + 60)->seconds();

        $this->expectException(AuthenticationException::class);
        $this->tokens->authenticate($pair->accessToken);
    }

    /**
     * CARRIES WEIGHT. Logout kills an access token that has not expired.
     *
     * This is the reason revocation lives on the session: the access token is
     * still within its own 15 minutes and must stop working anyway.
     */
    public function test_revoking_the_session_invalidates_an_unexpired_access_token(): void
    {
        $pair = $this->issueFor();
        $session = AuthSession::query()->findOrFail($pair->sessionId);

        $this->tokens->authenticate($pair->accessToken); // works before
        $this->tokens->revoke($session, SessionRevocationReason::Logout);

        $this->expectException(AuthenticationException::class);
        $this->tokens->authenticate($pair->accessToken);
    }

    public function test_a_session_past_its_absolute_expiry_stops_authenticating(): void
    {
        $pair = $this->issueFor();

        $this->travel(config('ridemate.auth.session_absolute_ttl') + 60)->seconds();

        $this->expectException(AuthenticationException::class);
        $this->tokens->authenticate($pair->accessToken);
    }

    /**
     * CARRIES WEIGHT. Suspension is 403, not 401.
     *
     * A 401 would send the client round the sign-in loop forever. The
     * credential is genuine; the account is not permitted.
     */
    public function test_a_suspended_account_is_forbidden_rather_than_unauthenticated(): void
    {
        $account = $this->createAccount();
        $pair = $this->issueFor($account);

        $account->status = AccountStatus::Suspended;
        $account->save();

        $this->expectException(AuthorizationException::class);
        $this->tokens->authenticate($pair->accessToken);
    }

    // -------------------------------------------------------------- rotation

    public function test_rotation_closes_the_old_generation_and_opens_the_next(): void
    {
        $first = $this->issueFor();
        $firstId = TokenSecret::parse(TokenKind::Refresh, $first->refreshToken)?->id;
        self::assertNotNull($firstId);

        $second = $this->tokens->rotate($first->refreshToken);

        $old = AuthToken::query()->findOrFail($firstId);
        self::assertTrue($old->isRotated());
        self::assertNotNull($old->succeeded_by_id);
        self::assertSame(2, AuthToken::query()->findOrFail($old->succeeded_by_id)->generation);

        // Same family throughout: rotation never opens a new session.
        self::assertSame($first->sessionId, $second->sessionId);

        $this->tokens->authenticate($second->accessToken);
    }

    /**
     * Deliberate: the previous access token survives its generation's rotation.
     *
     * Otherwise a proactive refresh would 401 every request already in flight
     * on the device.
     */
    public function test_the_previous_access_token_survives_rotation_until_it_expires(): void
    {
        $first = $this->issueFor();
        $this->tokens->rotate($first->refreshToken);

        $context = $this->tokens->authenticate($first->accessToken);
        self::assertSame($first->sessionId, $context->session->id);
    }

    // -------------------------------------------------------- reuse detection

    /**
     * CARRIES WEIGHT, AND IS THE POINT OF THE WHOLE SCHEMA.
     *
     * Reusing a rotated generation revokes the family, AND THE REVOCATION IS
     * COMMITTED. If the refusal were thrown from inside the rotation
     * transaction, the exception below would still be raised and the write
     * would be rolled back — a hole that an "expectException" test alone
     * cannot see. Hence the database assertion.
     */
    public function test_reusing_a_rotated_generation_revokes_the_family_and_commits_it(): void
    {
        $first = $this->issueFor();
        $this->tokens->rotate($first->refreshToken);

        try {
            $this->tokens->rotate($first->refreshToken);
            self::fail('reuse should have been refused');
        } catch (AuthenticationException) {
            // expected
        }

        $session = AuthSession::query()->findOrFail($first->sessionId);
        self::assertNotNull($session->revoked_at, 'the revocation must survive the refusal');
        self::assertSame(SessionRevocationReason::ReuseDetected, $session->revoked_reason);
    }

    /**
     * CARRIES WEIGHT. Detection reaches back through the whole chain.
     *
     * A design that overwrote one hash per session could only ever catch the
     * immediately previous generation. Because rotation appends, generation 1
     * is still recognisable after three more have been issued.
     */
    public function test_reuse_of_a_much_older_generation_is_still_detected(): void
    {
        $first = $this->issueFor();
        $second = $this->tokens->rotate($first->refreshToken);
        $third = $this->tokens->rotate($second->refreshToken);
        $this->tokens->rotate($third->refreshToken);

        try {
            $this->tokens->rotate($first->refreshToken);
            self::fail('reuse should have been refused');
        } catch (AuthenticationException) {
            // expected
        }

        self::assertSame(
            SessionRevocationReason::ReuseDetected,
            AuthSession::query()->findOrFail($first->sessionId)->revoked_reason,
        );
    }

    /**
     * CARRIES WEIGHT. The documented consequence of strict detection.
     *
     * This is what a genuine concurrent refresh looks like after the fact: the
     * winner was issued a valid pair, and the loser's replay then killed the
     * family — so the winner's brand-new access token stops working on its very
     * next request. The trade-off is intentional and is recorded here rather
     * than discovered in production.
     */
    public function test_reuse_invalidates_even_the_newest_access_token(): void
    {
        $first = $this->issueFor();
        $second = $this->tokens->rotate($first->refreshToken);

        $this->tokens->authenticate($second->accessToken); // valid right now

        try {
            $this->tokens->rotate($first->refreshToken);
        } catch (AuthenticationException) {
            // expected
        }

        $this->expectException(AuthenticationException::class);
        $this->tokens->authenticate($second->accessToken);
    }

    public function test_a_revoked_family_cannot_be_rotated(): void
    {
        $pair = $this->issueFor();
        $this->tokens->revoke(
            AuthSession::query()->findOrFail($pair->sessionId),
            SessionRevocationReason::Logout,
        );

        $this->expectException(AuthenticationException::class);
        $this->tokens->rotate($pair->refreshToken);
    }

    public function test_rotation_is_forbidden_for_a_suspended_account(): void
    {
        $account = $this->createAccount();
        $pair = $this->issueFor($account);

        $account->status = AccountStatus::Suspended;
        $account->save();

        $this->expectException(AuthorizationException::class);
        $this->tokens->rotate($pair->refreshToken);
    }

    // ------------------------------------------------------------ revocation

    /**
     * Idempotent, and the FIRST reason is the one that survives.
     *
     * A later call must not overwrite `reuse_detected` with `logout`, because
     * the first answer is the one that explains the incident.
     */
    public function test_revocation_is_idempotent_and_preserves_the_original_reason(): void
    {
        $pair = $this->issueFor();
        $session = AuthSession::query()->findOrFail($pair->sessionId);

        self::assertTrue($this->tokens->revoke($session, SessionRevocationReason::ReuseDetected));
        self::assertFalse($this->tokens->revoke($session, SessionRevocationReason::Logout));

        self::assertSame(
            SessionRevocationReason::ReuseDetected,
            $session->refresh()->revoked_reason,
        );
    }

    public function test_revoking_every_session_leaves_other_accounts_alone(): void
    {
        $mine = $this->createAccount('+905321234567');
        $theirs = $this->createAccount('+905329876543');

        $this->issueFor($mine);
        $this->issueFor($mine);
        $other = $this->issueFor($theirs);

        self::assertSame(2, $this->tokens->revokeAllFor($mine, SessionRevocationReason::Operator));

        $this->tokens->authenticate($other->accessToken);
    }

    /**
     * Neither credential may outlive the session that granted it.
     */
    public function test_credentials_are_capped_by_the_session_ceiling(): void
    {
        config(['ridemate.auth.session_absolute_ttl' => 60]);

        $pair = $this->issueFor();
        $token = AuthToken::query()->findOrFail(
            TokenSecret::parse(TokenKind::Access, $pair->accessToken)?->id,
        );
        $session = AuthSession::query()->findOrFail($pair->sessionId);

        self::assertLessThanOrEqual(
            $session->absolute_expires_at->getTimestamp(),
            $token->refresh_expires_at->getTimestamp(),
        );
        self::assertLessThanOrEqual(
            $session->absolute_expires_at->getTimestamp(),
            $token->access_expires_at->getTimestamp(),
        );
    }
}
