<?php

declare(strict_types=1);

namespace App\Registration;

use App\Models\Registration;
use App\Otp\OtpChannel;
use App\Otp\OtpService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Was this the right code for the destination this registration named — and if
 * so, record that it was proven.
 *
 * THE CALLER DOES NOT SUPPLY A DESTINATION, AND THAT IS THE WHOLE SECURITY
 * PROPERTY
 *
 * The parameters are a registration, a channel and a code. There is nowhere to
 * put an address or a number, so the attack this boundary exists to prevent —
 * verify a code sent to an address you control, attach the proof to a
 * registration naming somebody else's — is not something a check has to catch.
 * It cannot be expressed. The destination is read from the locked row, which is
 * the same row the proof is written to, so the two can never disagree.
 *
 * CONSUMPTION AND PROOF ARE ONE TRANSACTION
 *
 * A consumed challenge with no proof is a code that can never be verified again
 * on a registration that can therefore never complete. Proof with no
 * consumption is a code that can be spent twice. Both halves commit together or
 * neither does, which is why this owns the transaction and calls
 * `OtpService::verifyWithin()` rather than `verify()` — the latter owns its own,
 * and reaching the same guarantee through Laravel's savepoint nesting would be
 * a correctness property no reader could see.
 *
 * LOCK ORDER: REGISTRATION ROW, THEN CHALLENGE ROW. MANDATORY.
 *
 * The registration is locked below before `verifyWithin()` takes the challenge
 * row. Every path that needs both takes them in this order and there is no
 * inverse path — nothing starts from a challenge and reaches for a
 * registration, and `otp_challenges` carries no registration column precisely
 * so that nothing can. Two paths taking these two locks in opposite orders is a
 * deadlock that only appears under load, which is the worst time to find it.
 *
 * PROOF IS WRITE-ONCE
 *
 * A channel already proven is refused before any challenge is touched. Not
 * because a second verification would be wrong, but because moving the
 * timestamp would let anyone holding the credential refresh the age of a proof
 * they did not re-earn, and because consuming a live challenge to record
 * something already recorded spends a code for nothing. A resend after a proof
 * therefore cannot erase or refresh it either: this is the only writer, and it
 * declines.
 *
 * Every refusal is the same `false` — no challenge, expired, exhausted, wrong
 * code, unbound destination, already proven, registration ended. `OtpService`
 * cannot distinguish its own cases and neither does this, so a caller learns
 * nothing about a registration or a destination it does not hold.
 *
 * NOT REACHABLE FROM OUTSIDE
 *
 * No route resolves this and no controller calls it. A successful verification
 * still creates no account, opens no session and issues no token: what two
 * proofs entitle anyone to is the completion slice's question.
 */
final class VerifyRegistrationPasscode
{
    public function __construct(private readonly OtpService $otp) {}

    public function __invoke(Registration $registration, OtpChannel $channel, string $code): bool
    {
        return DB::transaction(function () use ($registration, $channel, $code): bool {
            // LOCK 1 OF 2. The registration, before the challenge. See above.
            $locked = Registration::query()
                ->whereKey($registration->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Registration) {
                return false;
            }

            // Expired or completed. Neither is revived here, and `completed_at`
            // is never written by this slice — completion is its own.
            if (! $locked->isAdvanceable()) {
                return false;
            }

            if ($locked->provenOn($channel)) {
                return false;
            }

            $destination = $locked->destinationOn($channel);

            if ($destination === null) {
                // Nothing was ever sent on this channel for this registration,
                // so there is no challenge of its own to find. Refused before the
                // OTP layer is asked, so an unbound channel cannot be used to
                // probe another registration's live challenges.
                return false;
            }

            // LOCK 2 OF 2, inside verifyWithin(). Consumes on success and
            // spends an attempt on a wrong code, exactly as the auth path does.
            if (! $this->otp->verifyWithin($channel, $destination, $code)) {
                return false;
            }

            $locked->{$locked->proofColumnOn($channel)} = CarbonImmutable::now();
            $locked->save();

            // The caller's instance stops being stale rather than silently
            // reporting the channel as unproven right after proving it.
            $registration->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
