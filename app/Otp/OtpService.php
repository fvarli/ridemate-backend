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
 * Everything here assumes the destination arrives ALREADY CANONICAL. That is
 * not laziness about validation — it is the point. Normalization happens once,
 * at the request boundary, so that a destination means one identity everywhere
 * downstream. A service that re-normalized would invite a second, subtly
 * different implementation of the same rule.
 *
 * A CHANNEL AND A DESTINATION, NOT A PHONE NUMBER
 *
 * The mechanics below — the hash, the expiry, the attempt ceiling, the two
 * endings — were never about phones; only the column name was. Every policy
 * read and every lock is now scoped by the PAIR, so a challenge on one channel
 * can neither be found by, invalidated by, nor rate-limited against the other.
 * Both channels are issued: `SendPasscode` for SMS, `SendEmailPasscode` for
 * email. They share one policy — the lifetime, the attempt ceiling, the
 * cooldown and the hourly cap are single values in configuration — and share
 * nothing else, because every count below is taken per pair.
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
    public function issue(OtpChannel $channel, string $destination): IssuedChallenge
    {
        return DB::transaction(function () use ($channel, $destination): IssuedChallenge {
            // Serializes every issuance for this identity. Two simultaneous
            // requests for the same number cannot interleave their policy
            // checks with each other's insert, which is what would otherwise
            // let both pass the cooldown and one hit the unique index.
            //
            // Transaction-scoped, so it releases on commit AND on rollback
            // without any unlock bookkeeping.
            DB::select(
                'select pg_advisory_xact_lock(?)',
                [self::lockKey($channel, $destination)],
            );

            $now = CarbonImmutable::now();

            $this->enforcePolicy($channel, $destination, $now);

            // Unconditional, and expired rows are included. The partial unique
            // index cannot know about expiry — `now()` is not IMMUTABLE — so an
            // expired row nobody has pruned would otherwise block this member
            // from ever receiving another passcode.
            OtpChallenge::query()
                ->where('channel', $channel)
                ->where('destination', $destination)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => $now]);

            $code = $this->generateCode();

            $challenge = new OtpChallenge;
            $challenge->channel = $channel;
            $challenge->destination = $destination;
            $challenge->code_hash = $this->hash($destination, $code);
            $challenge->expires_at = $now->addSeconds($this->setting('ttl'));
            $challenge->save();

            return new IssuedChallenge($challenge->id, $channel, $destination, $code);
        });
    }

    /**
     * Was this the right code?
     *
     * Consumes the challenge on success and counts the attempt on failure.
     * Every failure — no challenge, expired, exhausted, wrong — returns the
     * same false, because the caller must not be able to tell them apart.
     *
     * Owns its transaction, which is what a caller with nothing else to record
     * wants: the phone sign-in path verifies and then decides who that makes
     * you, and those are two decisions rather than one write.
     */
    public function verify(OtpChannel $channel, string $destination, string $code): bool
    {
        return DB::transaction(fn (): bool => $this->attempt($channel, $destination, $code));
    }

    /**
     * The same verification, for a caller that owns the transaction.
     *
     * WHY THIS EXISTS, AND WHY IT IS NOT A SECOND IMPLEMENTATION
     *
     * A caller that records what a successful verification MEANS has to commit
     * that meaning and the consumption together. Registration is the first:
     * consuming a challenge without writing the proof it earned spends a code
     * that can never be verified again and strands the member on a
     * registration that can never complete — and the reverse, proof without
     * consumption, is a code that can be spent twice.
     *
     * Calling `verify()` from inside another transaction would have worked, by
     * way of Laravel turning the inner `DB::transaction` into a savepoint. It
     * is the same guarantee reached by accident, and a reader would have to
     * know that to see it. This says it instead, and the guard below makes a
     * caller that forgot fail loudly rather than commit half the story.
     *
     * Both paths run the one `attempt()` below, so there is no second copy of
     * the attempt ceiling, the constant-time comparison or the consumption.
     *
     * LOCK ORDER. This takes the challenge row. Any caller that also locks a
     * registration row must take THAT one first — see
     * `App\Registration\VerifyRegistrationPasscode`.
     */
    public function verifyWithin(OtpChannel $channel, string $destination, string $code): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'Passcode verification within a caller transaction requires one to be open.',
            );
        }

        return $this->attempt($channel, $destination, $code);
    }

    /**
     * The verification itself. Assumes a transaction is already open.
     */
    private function attempt(OtpChannel $channel, string $destination, string $code): bool
    {
        // The row lock is what makes the attempt cap exact. Without it,
        // parallel guesses read the same counter and each writes back
        // "one more", so five concurrent requests spend one attempt.
        $challenge = OtpChallenge::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
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

        if (! hash_equals($challenge->code_hash, $this->hash($destination, $code))) {
            $challenge->attempts++;
            $challenge->save();

            return false;
        }

        $challenge->consumed_at = CarbonImmutable::now();
        $challenge->save();

        return true;
    }

    /**
     * Cooldown and hourly cap, both counted from rows rather than a counter.
     *
     * Exact, durable across restarts, and readable during an incident — and it
     * needs no cache store, which is the whole reason this limit does not live
     * with the per-IP ones.
     */
    private function enforcePolicy(
        OtpChannel $channel,
        string $destination,
        CarbonImmutable $now,
    ): void {
        $newest = OtpChallenge::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->orderByDesc('created_at')
            ->first();

        if ($newest instanceof OtpChallenge
            && $newest->created_at->addSeconds($this->setting('resend_cooldown'))->isFuture()) {
            throw self::tooMany();
        }

        $recent = OtpChallenge::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->where('created_at', '>', $now->subHour())
            ->count();

        if ($recent >= $this->setting('max_per_destination_per_hour')) {
            throw self::tooMany();
        }
    }

    /**
     * One message for both limits, and for every channel.
     *
     * Distinguishing "too soon" from "too many this hour" would tell a caller
     * how much history a destination has. Naming the KIND of destination would
     * tell them something else: which channel a refusal came from, and
     * therefore which channel the service was willing to try. So the text says
     * neither — no number, no address, no channel. It is also fixed text
     * containing nothing the caller supplied, because the renderer puts it in
     * the response body.
     *
     * The status and the error code are unchanged. Only the sentence is.
     */
    private static function tooMany(): TooManyRequestsHttpException
    {
        return new TooManyRequestsHttpException(
            null,
            'Too many passcode requests.',
        );
    }

    /**
     * A 64-bit advisory-lock key derived from the canonical identity.
     *
     * The channel is part of that identity: locking on the destination alone
     * would serialize a member's email issuance against their own SMS one, for
     * no reason. Transaction-scoped, so widening the key changes nothing about
     * any lock already held.
     *
     * PostgreSQL's own hashtext() would be the obvious choice and returns only
     * 32 bits, where a birthday collision becomes likely around seventy-odd
     * thousand distinct numbers. Two unrelated members sharing a lock would not
     * corrupt anything — the partial unique index still holds — but it would
     * serialize strangers against each other for no reason. A domain-separated
     * SHA-256 prefix costs the same and does not.
     */
    private static function lockKey(OtpChannel $channel, string $destination): int
    {
        $unpacked = unpack('J', substr(
            hash('sha256', 'rm.otp.lock.v1:'.$channel->value.':'.$destination, true),
            0,
            8,
        ));

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
     * Keyed, and bound to the destination.
     *
     * A plain digest of six digits is a million-entry rainbow table that fits
     * in memory, so a leaked table would be equivalent to leaking the codes.
     * The key means an attacker needs APP_KEY as well, and including the
     * destination means a hash lifted from one row cannot be replayed against
     * another.
     *
     * THE CHANNEL IS DELIBERATELY NOT IN HERE, AND `v1` IS DELIBERATELY KEPT
     *
     * Binding it in would separate two domains that cannot collide anyway: an
     * E.164 number and an email address are never the same string, so no hash
     * is valid on both channels already. What changing the input WOULD do is
     * invalidate every passcode in flight at the moment of deploy — a member
     * mid-sign-in would be told their correct code is wrong, with nothing to
     * explain it. A version bump has to buy something, and this one would not.
     */
    private function hash(string $destination, string $code): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY is not configured.');
        }

        return hash_hmac('sha256', 'rm.otp.v1:'.$destination.':'.$code, $key);
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
