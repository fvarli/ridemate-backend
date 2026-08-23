<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Account;
use App\Models\AuthSession;
use App\Models\AuthToken;
use App\Models\SessionRevocationReason;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues, validates, rotates and revokes RideMate's credentials.
 *
 * WHY THIS IS NOT SANCTUM
 *
 * Sanctum contributes bearer parsing, a token table, SHA-256 hashing and a
 * guard. It contributes nothing to refresh rotation, token families or reuse
 * detection — which is the entire security-relevant part. Building on it meant
 * two token systems, two hashing paths and two revocation paths, in exchange
 * for roughly the eighty lines below. JWT was rejected for the opposite
 * reason: its one real advantage is verifying without a database read, and
 * immediate revocation forces that read anyway.
 *
 * THE THREE CLOCKS
 *
 * An access token lasts minutes. A refresh generation lasts weeks and is
 * replaced every time it is used. A session lasts months and cannot be
 * extended by either. Together they mean a stolen access token expires on its
 * own, a stolen refresh token is detected the moment the real client refreshes,
 * and no session survives indefinitely just because someone kept refreshing it.
 */
final class TokenService
{
    /**
     * Opens a session and mints its first generation.
     *
     * Called only after a passcode has been verified. Nothing else may create
     * a session.
     */
    public function issue(Account $account, DeviceDescription $device): IssuedTokenPair
    {
        $session = new AuthSession;
        $session->account_id = $account->id;
        $session->device_name = $device->name;
        $session->platform = $device->platform;
        $session->app_version = $device->appVersion;
        $session->absolute_expires_at = CarbonImmutable::now()
            ->addSeconds($this->setting('session_absolute_ttl'));
        $session->save();

        return $this->mint($session, 1)['pair'];
    }

    /**
     * Resolves an access credential, or refuses.
     *
     * `rotated_at` is deliberately NOT consulted. An access token stays valid
     * for its own lifetime after its generation has been exchanged, so a
     * proactive refresh does not 401 every request already in flight on the
     * device. Revocation still bites immediately, because it lives on the
     * session and this path joins it.
     *
     * @throws AuthenticationException|AuthorizationException
     */
    public function authenticate(string $credential): AuthContext
    {
        $parsed = TokenSecret::parse(TokenKind::Access, $credential);

        if ($parsed === null) {
            throw self::invalid();
        }

        // Eager-loaded rather than joined and hand-aliased. Three primary-key
        // reads on indexed uuid columns cost nothing at this scale, and
        // aliasing security-critical columns by hand is a way to check the
        // wrong one.
        $token = AuthToken::query()->with('session.account')->find($parsed->id);

        if ($token === null) {
            throw self::invalid();
        }

        if (! TokenSecret::matches($token->access_token_hash, TokenKind::Access, $parsed->secret)) {
            throw self::invalid();
        }

        if ($token->access_expires_at->isPast()) {
            throw self::invalid();
        }

        $session = $token->session;

        if (! $session->isLive()) {
            throw self::invalid();
        }

        $account = $session->account;

        // 403, not 401. The credential is genuine and the account is not
        // permitted, and the client has to tell a member "your account is
        // suspended" instead of sending them round the sign-in loop forever.
        if (! $account->isActive()) {
            throw self::suspended();
        }

        return new AuthContext($account, $session, $token);
    }

    /**
     * Exchanges a refresh generation for the next one.
     *
     * @throws AuthenticationException|AuthorizationException
     */
    public function rotate(string $credential): IssuedTokenPair
    {
        // The refusal is returned from the transaction and thrown outside it.
        // See RotationRefusal: throwing from within would roll back the
        // revocation that reuse detection just performed.
        $outcome = DB::transaction(fn (): IssuedTokenPair|RotationRefusal => $this->attemptRotation($credential));

        if ($outcome instanceof RotationRefusal) {
            throw match ($outcome) {
                RotationRefusal::Invalid => self::invalid(),
                RotationRefusal::Suspended => self::suspended(),
            };
        }

        return $outcome;
    }

    /**
     * The only revocation path in the system.
     *
     * One UPDATE kills every access token and every refresh generation in the
     * family at once, because both validation paths join this row. Nothing
     * walks the token chain, and nothing needs to.
     *
     * Idempotent: the `whereNull` means a second call is a no-op rather than
     * an overwrite, so the original reason and time survive.
     */
    public function revoke(AuthSession $session, SessionRevocationReason $reason): bool
    {
        return AuthSession::query()
            ->whereKey($session->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => CarbonImmutable::now(),
                'revoked_reason' => $reason->value,
            ]) > 0;
    }

    /** Every live session for one account. Used by suspension and by operators. */
    public function revokeAllFor(Account $account, SessionRevocationReason $reason): int
    {
        return AuthSession::query()
            ->where('account_id', $account->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => CarbonImmutable::now(),
                'revoked_reason' => $reason->value,
            ]);
    }

    /**
     * Runs inside the rotation transaction. Returns, never throws.
     */
    private function attemptRotation(string $credential): IssuedTokenPair|RotationRefusal
    {
        $parsed = TokenSecret::parse(TokenKind::Refresh, $credential);

        if ($parsed === null) {
            return RotationRefusal::Invalid;
        }

        // The lock that makes concurrent refresh safe. Two requests carrying
        // the same generation serialize here; under READ COMMITTED the second
        // re-reads the row once the lock is granted, so it sees the first's
        // committed `rotated_at` rather than its own stale snapshot.
        //
        // auth_tokens is ALWAYS locked before auth_sessions. No path takes
        // them in the other order, which is the whole deadlock strategy.
        $token = AuthToken::query()->whereKey($parsed->id)->lockForUpdate()->first();

        if ($token === null) {
            return RotationRefusal::Invalid;
        }

        if (! TokenSecret::matches($token->refresh_token_hash, TokenKind::Refresh, $parsed->secret)) {
            return RotationRefusal::Invalid;
        }

        $session = $token->session()->first();

        if (! $session instanceof AuthSession) {
            return RotationRefusal::Invalid;
        }

        // REUSE. This generation was already exchanged, so either the
        // credential was stolen or a client retried a refresh whose response
        // it never received. The server cannot tell those apart and resolves
        // the ambiguity in favour of security: the whole family dies.
        if ($token->isRotated()) {
            $this->revoke($session, SessionRevocationReason::ReuseDetected);

            return RotationRefusal::Invalid;
        }

        if (! $session->isLive() || $token->refresh_expires_at->isPast()) {
            return RotationRefusal::Invalid;
        }

        $account = $session->account()->first();

        if (! $account instanceof Account) {
            return RotationRefusal::Invalid;
        }

        if (! $account->isActive()) {
            return RotationRefusal::Suspended;
        }

        $minted = $this->mint($session, $token->generation + 1);

        $token->rotated_at = CarbonImmutable::now();
        $token->succeeded_by_id = $minted['token']->id;
        $token->save();

        return $minted['pair'];
    }

    /**
     * Writes one generation and returns its plaintext, which exists nowhere
     * else and is never persisted.
     *
     * @return array{pair: IssuedTokenPair, token: AuthToken}
     */
    private function mint(AuthSession $session, int $generation): array
    {
        $now = CarbonImmutable::now();
        $accessTtl = $this->setting('access_ttl');

        $accessSecret = TokenSecret::generate();
        $refreshSecret = TokenSecret::generate();

        $token = new AuthToken;
        $token->id = $token->newUniqueId();
        $token->session_id = $session->id;
        $token->generation = $generation;
        $token->access_token_hash = TokenSecret::hash(TokenKind::Access, $accessSecret);
        $token->refresh_token_hash = TokenSecret::hash(TokenKind::Refresh, $refreshSecret);

        // Neither credential may outlive the session that granted it. isLive()
        // would refuse them anyway, but a row claiming an expiry it cannot
        // honour is a row that misleads whoever reads it during an incident.
        $token->access_expires_at = self::earliest(
            $now->addSeconds($accessTtl),
            $session->absolute_expires_at,
        );
        $token->refresh_expires_at = self::earliest(
            $now->addSeconds($this->setting('refresh_inactivity')),
            $session->absolute_expires_at,
        );
        $token->save();

        return [
            'pair' => new IssuedTokenPair(
                accessToken: TokenSecret::compose(TokenKind::Access, $token->id, $accessSecret),
                refreshToken: TokenSecret::compose(TokenKind::Refresh, $token->id, $refreshSecret),
                sessionId: $session->id,
                // Carbon 3 returns a float here; the contract publishes whole seconds.
                expiresIn: max(0, (int) $token->access_expires_at->diffInSeconds($now, true)),
            ),
            'token' => $token,
        ];
    }

    private static function earliest(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lessThan($b) ? $a : $b;
    }

    private static function invalid(): AuthenticationException
    {
        // Fixed, developer-facing, and never anything the caller supplied.
        // One message for every refusal: malformed, unknown, mismatched,
        // expired, revoked and reused are indistinguishable from outside.
        return new AuthenticationException('The credential is not valid.');
    }

    private static function suspended(): AuthorizationException
    {
        return new AuthorizationException('The account is suspended.');
    }

    private function setting(string $key): int
    {
        $value = config("ridemate.auth.$key");

        if (! is_numeric($value)) {
            throw new RuntimeException("ridemate.auth.$key is not configured as a number.");
        }

        return (int) $value;
    }
}
