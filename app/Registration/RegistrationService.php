<?php

declare(strict_types=1);

namespace App\Registration;

use App\Models\Registration;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
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
 * is a place to accumulate proof; what proof eventually entitles anyone to is
 * the completion slice's question, and it does not exist yet.
 *
 * It also issues no passcode and verifies none. `email_verified_at` and
 * `phone_verified_at` are written by nothing in this slice — attaching a proof
 * has to consume an OTP challenge and record the proof in ONE transaction, or a
 * crash between the two spends a challenge that can never be verified again and
 * strands the member on a registration that can never complete. That ordering,
 * and the lock order it needs (registration before challenge), belong to the
 * slice that implements it.
 *
 * NOT REACHABLE FROM OUTSIDE
 *
 * No route resolves this and no controller calls it. It is an internal
 * application capability, written and tested before the slice that decides what
 * a registration may become.
 */
final class RegistrationService
{
    /**
     * Begins a registration and mints its credential.
     *
     * The registration starts empty: no destination is named, because naming
     * one is what `bindEmail()` and `bindPhone()` do, and neither has happened.
     * That is also why this takes no arguments — a registration that had to be
     * told an address up front would fix the order the two channels are proven
     * in, and nothing about the domain requires one.
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
     * Names the address this registration will prove.
     *
     * Canonicalization happens here and only here, through the same
     * `EmailAddress` the OTP capability normalizes with. That is not tidiness:
     * the string written to this row has to be byte-identical to the one
     * `otp_challenges.destination` holds, or the advisory lock, the partial
     * unique index and the HMAC are keyed on an identity this row does not
     * name. A second opinion about what an address is would be a second
     * identity.
     *
     * Rebinding before the proof is fine — a member who mistyped an address must
     * be able to correct it.
     *
     * @throws InvalidIdentifier when the value is not an address at all.
     */
    public function bindEmail(Registration $registration, string $email): void
    {
        $this->assertBindable($registration, $registration->email_verified_at !== null);

        $registration->email = EmailAddress::normalize($email)
            ?? throw new InvalidIdentifier('The registration identifier is not a valid email address.');

        $registration->save();
    }

    /**
     * Names the number this registration will prove.
     *
     * The phone counterpart of `bindEmail()`, through `PhoneNumber` for the
     * same reason and with the same consequence: `accounts.phone_e164` will
     * eventually be written from this value, and `unique(phone_e164)` is an
     * identity constraint only while every writer canonicalizes the same way.
     *
     * @throws InvalidIdentifier when the value is not a phone number at all.
     */
    public function bindPhone(Registration $registration, string $phone): void
    {
        $this->assertBindable($registration, $registration->phone_verified_at !== null);

        $registration->phone_e164 = PhoneNumber::normalize($phone)
            ?? throw new InvalidIdentifier('The registration identifier is not a valid phone number.');

        $registration->save();
    }

    /**
     * Both guards a caller is expected to have satisfied already.
     *
     * `RuntimeException` rather than a refusal, as `SendPasscode` throws on
     * being wrapped in a transaction: these are not outcomes a member can
     * produce. `resolve()` returns only advanceable registrations, so an
     * unadvanceable one arriving here means the caller skipped the gate — and
     * changing an identifier whose proof has already been earned would silently
     * transfer that proof to a destination nobody proved, which is the one
     * thing this aggregate exists to prevent.
     *
     * Neither message names the identifier or the registration.
     */
    private function assertBindable(Registration $registration, bool $alreadyProven): void
    {
        if (! $registration->isAdvanceable()) {
            throw new RuntimeException(
                'The registration can no longer be advanced.',
            );
        }

        if ($alreadyProven) {
            throw new RuntimeException(
                'A proven registration identifier cannot be rebound.',
            );
        }
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
