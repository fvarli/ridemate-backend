<?php

declare(strict_types=1);

namespace App\Otp;

use App\Models\OtpChallenge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Issues and verifies one-time passcodes.
 *
 * Everything here assumes the phone number arrives ALREADY CANONICAL. That is
 * not laziness about validation — it is the point. Normalization happens once,
 * at the request boundary, so that `phone_e164` means one identity everywhere
 * downstream. A service that re-normalized would invite a second, subtly
 * different implementation of the same rule.
 *
 * WHAT THIS SERVICE DOES NOT DO
 *
 * It never touches `accounts`. Issuance cannot reveal whether an account
 * exists because it has no way to find out, and verification answers only
 * "was this the right code" — resolving the member is the caller's job. That
 * separation is what makes enumeration resistance structural rather than
 * something the controller has to remember.
 */
final class OtpService
{
    /**
     * Writes a challenge and returns its plaintext.
     *
     * The caller MUST dispatch after this returns, never inside a transaction
     * of its own — see the class comment on SmsSender.
     *
     * @throws TooManyRequestsHttpException when policy refuses another passcode.
     */
    public function issue(string $phoneE164): IssuedChallenge
    {
        return DB::transaction(function () use ($phoneE164): IssuedChallenge {
            // Serializes every issuance for this identity. Two simultaneous
            // requests for the same number cannot interleave their policy
            // checks with each other's insert, which is what would otherwise
            // let both pass the cooldown and one hit the unique index.
            //
            // Transaction-scoped, so it releases on commit AND on rollback
            // without any unlock bookkeeping.
            DB::select('select pg_advisory_xact_lock(?)', [self::lockKey($phoneE164)]);

            $now = CarbonImmutable::now();

            $this->enforcePolicy($phoneE164, $now);

            // Unconditional, and expired rows are included. The partial unique
            // index cannot know about expiry — `now()` is not IMMUTABLE — so an
            // expired row nobody has pruned would otherwise block this member
            // from ever receiving another passcode.
            OtpChallenge::query()
                ->where('phone_e164', $phoneE164)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => $now]);

            $code = $this->generateCode();

            $challenge = new OtpChallenge;
            $challenge->phone_e164 = $phoneE164;
            $challenge->code_hash = $this->hash($phoneE164, $code);
            $challenge->expires_at = $now->addSeconds($this->setting('ttl'));
            $challenge->save();

            return new IssuedChallenge($challenge->id, $phoneE164, $code);
        });
    }

    /**
     * Was this the right code?
     *
     * Consumes the challenge on success and counts the attempt on failure.
     * Every failure — no challenge, expired, exhausted, wrong — returns the
     * same false, because the caller must not be able to tell them apart.
     */
    public function verify(string $phoneE164, string $code): bool
    {
        return DB::transaction(function () use ($phoneE164, $code): bool {
            // The row lock is what makes the attempt cap exact. Without it,
            // parallel guesses read the same counter and each writes back
            // "one more", so five concurrent requests spend one attempt.
            $challenge = OtpChallenge::query()
                ->where('phone_e164', $phoneE164)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->lockForUpdate()
                ->first();

            if (! $challenge instanceof OtpChallenge) {
                return false;
            }

            if (! $challenge->isUsable($this->setting('max_attempts'))) {
                return false;
            }

            if (! hash_equals($challenge->code_hash, $this->hash($phoneE164, $code))) {
                $challenge->attempts++;
                $challenge->save();

                return false;
            }

            $challenge->consumed_at = CarbonImmutable::now();
            $challenge->save();

            return true;
        });
    }

    /**
     * Cooldown and hourly cap, both counted from rows rather than a counter.
     *
     * Exact, durable across restarts, and readable during an incident — and it
     * needs no cache store, which is the whole reason this limit does not live
     * with the per-IP ones.
     */
    private function enforcePolicy(string $phoneE164, CarbonImmutable $now): void
    {
        $newest = OtpChallenge::query()
            ->where('phone_e164', $phoneE164)
            ->orderByDesc('created_at')
            ->first();

        if ($newest instanceof OtpChallenge
            && $newest->created_at->addSeconds($this->setting('resend_cooldown'))->isFuture()) {
            throw self::tooMany();
        }

        $recent = OtpChallenge::query()
            ->where('phone_e164', $phoneE164)
            ->where('created_at', '>', $now->subHour())
            ->count();

        if ($recent >= $this->setting('max_per_phone_per_hour')) {
            throw self::tooMany();
        }
    }

    /**
     * One message for both limits.
     *
     * Distinguishing "too soon" from "too many this hour" would tell a caller
     * how much history a number has. It is also fixed text containing nothing
     * the caller supplied, because the renderer puts it in the response body.
     */
    private static function tooMany(): TooManyRequestsHttpException
    {
        return new TooManyRequestsHttpException(
            null,
            'Too many passcode requests for this number.',
        );
    }

    /**
     * A 64-bit advisory-lock key derived from the canonical identity.
     *
     * PostgreSQL's own hashtext() would be the obvious choice and returns only
     * 32 bits, where a birthday collision becomes likely around seventy-odd
     * thousand distinct numbers. Two unrelated members sharing a lock would not
     * corrupt anything — the partial unique index still holds — but it would
     * serialize strangers against each other for no reason. A domain-separated
     * SHA-256 prefix costs the same and does not.
     */
    private static function lockKey(string $phoneE164): int
    {
        $unpacked = unpack('J', substr(hash('sha256', 'rm.otp.lock.v1:'.$phoneE164, true), 0, 8));

        if ($unpacked === false || ! isset($unpacked[1]) || ! is_int($unpacked[1])) {
            throw new RuntimeException('The advisory lock key could not be derived.');
        }

        // PHP integers are signed 64-bit, so a high-bit digest yields a
        // negative value — which is exactly the bigint domain
        // pg_advisory_xact_lock accepts.
        return $unpacked[1];
    }

    private function generateCode(): string
    {
        $length = $this->setting('length');

        // random_int, not rand or mt_rand: this is a credential.
        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * Keyed, and bound to the number.
     *
     * A plain digest of six digits is a million-entry rainbow table that fits
     * in memory, so a leaked table would be equivalent to leaking the codes.
     * The key means an attacker needs APP_KEY as well, and including the phone
     * means a hash lifted from one row cannot be replayed against another.
     */
    private function hash(string $phoneE164, string $code): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY is not configured.');
        }

        return hash_hmac('sha256', 'rm.otp.v1:'.$phoneE164.':'.$code, $key);
    }

    private function setting(string $key): int
    {
        $value = config("ridemate.otp.$key");

        if (! is_numeric($value)) {
            throw new RuntimeException("ridemate.otp.$key is not configured as a number.");
        }

        return (int) $value;
    }
}
