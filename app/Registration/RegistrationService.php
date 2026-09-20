<?php

declare(strict_types=1);

namespace App\Registration;

use App\Models\Registration;
use App\Otp\OtpChannel;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mints pre-account registrations, resolves their credentials, and binds the
 * destinations a proof will later be attached to.
 *
 * WHAT THIS SERVICE DOES NOT DO
 *
 * It never touches `accounts`, never opens a session and never issues a token.
 * There is no code path from here to `TokenService`, and the credential it
 * mints is refused by every authenticated route by construction. A registration
 * is a place to accumulate proof; turning two proofs into an account is
 * `CompleteRegistration`, which owns that transaction and is equally
 * unreachable from outside.
 *
 * It also issues no passcode and verifies none. Those are
 * `SendRegistrationPasscode` and `VerifyRegistrationPasscode`, which sit beside
 * this and own the transaction that writes a proof. What this owns is the
 * BINDING: which destination a registration names on a channel, canonicalized
 * once, through the same value objects every other writer uses.
 *
 * REACHED FROM OUTSIDE ONLY THROUGH CONTROLLERS
 *
 * Phase 18 S4e gave registration four endpoints, and they resolve this — but no
 * route targets it directly, so nothing reaches `start()`, `resolve()` or
 * `bind()` without a request class and an error mapping in front of it. What is
 * unchanged is everything that made it safe while it was internal: it still
 * never touches `accounts`, never opens a session and never issues a token, and
 * there is still no code path from here to `TokenService`.
 */
final class RegistrationService
{
    /**
     * Begins a registration and mints its credential.
     *
     * The registration starts empty: no destination is named, because naming
     * one is what `bind()` does, and it has not happened. That is also why this
     * takes no arguments — a registration that had to be told an address up
     * front would fix the order the two channels are proven in, and nothing
     * about the domain requires one.
     */
    public function start(): MintedRegistration
    {
        $secret = RegistrationSecret::generate();

        $registration = new Registration;
        $registration->credential_hash = RegistrationSecret::hash($secret);
        $registration->expires_at = CarbonImmutable::now()->addSeconds($this->ttl());
        $registration->save();

        return new MintedRegistration(
            $registration->id,
            RegistrationSecret::compose($registration->id, $secret),
            $registration->expires_at,
        );
    }

    /**
     * The registration this credential names, if it may still be advanced.
     *
     * Every refusal is the same null — malformed, unknown, the wrong secret,
     * expired, already completed. They are genuinely indistinguishable to the
     * caller, which is the cheapest guarantee that a credential somebody does
     * not hold cannot be probed for whether it ever existed or how it ended.
     *
     * A credential may be resolved as many times as the registration lives.
     * That is ordinary continuation — two OTP rounds and a completion are
     * three requests at least — and it is deliberately NOT the refresh-token
     * rule: re-presenting this one is not reuse, and must never revoke
     * anything.
     *
     * It stops the moment completion commits, and that is the boundary that
     * matters: `isAdvanceable()` is false once `completed_at` is set, so a
     * credential whose registration produced an account cannot be presented
     * for a second one.
     */
    public function resolve(string $credential): ?Registration
    {
        $parsed = RegistrationSecret::parse($credential);

        if ($parsed === null) {
            return null;
        }

        $registration = Registration::query()->find($parsed->registrationId);

        if (! $registration instanceof Registration) {
            return null;
        }

        if (! RegistrationSecret::matches($registration->credential_hash, $parsed->secret)) {
            return null;
        }

        if (! $registration->isAdvanceable()) {
            return null;
        }

        return $registration;
    }

    /**
     * Names the destination this registration will prove on a channel.
     *
     * CANONICALIZATION HAPPENS HERE AND ONLY HERE
     *
     * Through the same `EmailAddress` and `PhoneNumber` every other writer
     * uses. That is not tidiness: the string written to this row has to be
     * byte-identical to the one `otp_challenges.destination` holds, or the
     * advisory lock, the partial unique index and the HMAC are keyed on an
     * identity this row does not name. A second opinion about what an address
     * is would be a second identity — which is also why no provider-specific
     * folding happens anywhere: a dot and a `+tag` are part of an address.
     *
     * ONCE BOUND, A DESTINATION STAYS BOUND
     *
     * Binding the same canonical value again is a no-op, because a resend must
     * work. Binding a DIFFERENT one is refused, whether or not the first was
     * ever proven. Rebinding was allowed when this row was first written, on
     * the reasoning that a member who mistyped should be able to correct it —
     * and nothing in the repository ever required it: there is no public
     * registration surface, no client flow and no test outside this layer that
     * asks for it. What it did buy was a window in which a challenge already
     * sent to one destination outlives the registration naming it, which is a
     * strictly worse thing to own than the correction it enabled. A member who
     * typed the wrong address starts another registration; they are cheap,
     * short-lived, and nothing is unique across them.
     *
     * LOCK ORDER: the registration row, and nothing else. Two sends racing on
     * one registration would otherwise both read "nothing bound" and the loser
     * would overwrite the winner, which is the invariant above failing exactly
     * when it matters.
     *
     * ITS ONE CALLER IS `SendRegistrationPasscode`, AND THAT IS LOAD-BEARING
     *
     * A destination is named at the moment a code is sent to it, never on its
     * own. That is what stops a registration naming an address it never asked
     * for a code at: issuance invalidates every unresolved challenge for that
     * destination, so a second registration comes to name one only by killing
     * whatever the first was holding.
     *
     * The residual, which is accepted rather than hidden: two registrations
     * naming ONE destination share that destination's single live challenge,
     * and the last send wins it. `otp_challenges` carries no registration
     * column — the OTP layer never learns what a code is for — so the pair is
     * as close as this gets without coupling the two. It is not a way in: the
     * code still goes to the destination, so redeeming it still means holding
     * that mailbox or that phone.
     *
     * WHY THE REFUSALS ARE TYPED
     *
     * They were three bare `RuntimeException`s while nothing public called
     * this. A public endpoint has to answer a vanished-or-ended registration
     * and an already-bound channel differently — the first is a credential that
     * stopped working, the second is a state a client can act on — and telling
     * them apart from an untyped exception means matching on its message. See
     * `RegistrationAdvanceRefused`.
     *
     * @throws InvalidIdentifier when the value is not a destination of this kind.
     * @throws RegistrationAdvanceRefused when the registration has ended, or the
     *                                    channel already names another destination.
     */
    public function bind(Registration $registration, OtpChannel $channel, string $destination): void
    {
        $canonical = $this->canonicalize($channel, $destination);

        DB::transaction(function () use ($registration, $channel, $canonical): void {
            $locked = Registration::query()
                ->whereKey($registration->getKey())
                ->lockForUpdate()
                ->first();

            // Gone and ended are one refusal, exactly as `resolve()` makes them
            // one null: from outside they are the same ending.
            if (! $locked instanceof Registration || ! $locked->isAdvanceable()) {
                throw RegistrationAdvanceRefused::registrationEnded();
            }

            $bound = $locked->destinationOn($channel);

            if ($bound !== null && $bound !== $canonical) {
                // Deliberately says neither destination, and does not say
                // whether the bound one was proven. Both are facts about an
                // identity the caller has just demonstrated it does not know.
                throw RegistrationAdvanceRefused::channelAlreadyBound();
            }

            if ($bound === null) {
                $locked->{$locked->destinationColumnOn($channel)} = $canonical;
                $locked->save();
            }

            // The caller's instance stops being stale, so a send that binds and
            // then reads the destination back gets the committed one.
            $registration->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** @throws InvalidIdentifier */
    private function canonicalize(OtpChannel $channel, string $destination): string
    {
        return match ($channel) {
            OtpChannel::Email => EmailAddress::normalize($destination)
                ?? throw new InvalidIdentifier('The registration identifier is not a valid email address.'),
            OtpChannel::Sms => PhoneNumber::normalize($destination)
                ?? throw new InvalidIdentifier('The registration identifier is not a valid phone number.'),
        };
    }

    private function ttl(): int
    {
        $value = config('ridemate.registration.ttl');

        if (! is_numeric($value)) {
            throw new RuntimeException('ridemate.registration.ttl is not configured as a number.');
        }

        return (int) $value;
    }
}
