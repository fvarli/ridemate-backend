<?php

declare(strict_types=1);

namespace App\Otp;

use InvalidArgumentException;

/**
 * Who a challenge belongs to, and therefore who may spend it.
 *
 * WHY A CHANNEL AND A DESTINATION WERE NOT ENOUGH
 *
 * A live challenge used to be identified by `(channel, destination)` alone.
 * That was exactly right while every challenge meant the same thing — prove
 * this number, sign this person in — because two challenges for one number
 * were two attempts at one act.
 *
 * Registration broke that assumption, and not by a little. Several
 * registrations may name one address at once, deliberately: nothing is unique
 * across in-flight registrations, because a uniqueness rule there would let
 * anyone bar an address they do not own. So "the live challenge for this
 * address" stopped naming one thing. Without a scope, a code delivered for one
 * registration verifies another that merely bound the same address, and a
 * registration code could be spent at `POST /auth/otp/verify` to open an
 * account — the destination matched, and nothing else was being asked.
 *
 * Reachability was not the problem. `otp_challenges` is the boundary, and a
 * boundary that holds only because no caller currently crosses it is not one.
 *
 * WHAT THIS DOES AND DOES NOT PARTITION
 *
 * It partitions IDENTITY: which unresolved row a lookup may find, which rows an
 * issuance may invalidate, and which uniqueness rule applies. Two scopes cannot
 * see, consume or supersede one another's challenges.
 *
 * It deliberately does NOT partition the ABUSE BUDGET. The cooldown, the hourly
 * cap and the advisory lock stay keyed on `(channel, destination)` across every
 * scope, because the thing being protected is the address itself — the person
 * whose inbox or handset receives the message. Scoping the budget would let an
 * attacker mint a hundred registrations and send a hundred times the passcodes
 * to one number, which is the attack the budget exists for. See
 * `OtpService::issue()`.
 *
 * NOT AN ENUM, AND NOT A NULLABLE STRING PARAMETER
 *
 * Not an enum, because one of the two cases carries a value. Not a bare
 * `?string` defaulted to null either: `otp_challenges` learned this lesson when
 * `channel` arrived with a default and the default was then dropped, because
 * "the day two channels exist an omitted one would silently become SMS". An
 * omitted scope would silently become the one that signs people in.
 */
final readonly class OtpScope
{
    private function __construct(public ?string $registrationId) {}

    /**
     * A challenge that belongs to no registration.
     *
     * The namespace `POST /auth/otp` issues into and `POST /auth/otp/verify`
     * reads from, and the one the internal standalone Email OTP capability
     * uses. Identified by `(channel, destination)`, exactly as every challenge
     * was before registration existed.
     */
    public static function standalone(): self
    {
        return new self(null);
    }

    /**
     * A challenge that belongs to one pre-account registration.
     *
     * Identified by `(registration_id, channel)`. The id is passed as a plain
     * string on purpose: this layer issues and verifies passcodes and has no
     * business knowing what a registration is, only that a challenge may belong
     * to something that is not a sign-in.
     */
    public static function forRegistration(string $registrationId): self
    {
        if ($registrationId === '') {
            throw new InvalidArgumentException('A registration scope needs a registration id.');
        }

        return new self($registrationId);
    }

    public function isStandalone(): bool
    {
        return $this->registrationId === null;
    }
}
