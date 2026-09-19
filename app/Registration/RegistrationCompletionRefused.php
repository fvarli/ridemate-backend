<?php

declare(strict_types=1);

namespace App\Registration;

use RuntimeException;

/**
 * The registration domain refusing to produce an account, and why.
 *
 * Carries a `RefusalReason` rather than an HTTP status, for the reason
 * `App\Trips\TripRefused` gives: the domain knows what it refused, and the
 * layer above knows what that is worth. Here there is no layer above yet, which
 * is precisely why the reason must not be an HTTP concept.
 *
 * THROWN FROM INSIDE THE COMPLETION TRANSACTION, ON PURPOSE
 *
 * Every refusal must leave the registration uncompleted and no account behind,
 * so unwinding the transaction IS the refusal. This is the opposite of
 * `App\Auth\RotationRefusal`, which is returned rather than thrown because
 * throwing would have rolled back the revocation reuse detection had just
 * performed. Nothing here has anything worth keeping on the way out.
 *
 * NO MESSAGE MAY NAME AN IDENTIFIER
 *
 * Not the address, not the number, not the credential. These messages are
 * developer-facing and reach the exception renderer, which puts them in a
 * response body in a debug build — and an address is exactly what an
 * enumeration attempt is looking for. They are fixed strings for that reason,
 * never anything a caller supplied.
 */
final class RegistrationCompletionRefused extends RuntimeException
{
    private function __construct(
        public readonly RefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function registrationEnded(): self
    {
        return new self(
            RefusalReason::RegistrationEnded,
            'That registration can no longer be advanced.',
        );
    }

    public static function notFullyProven(): self
    {
        return new self(
            RefusalReason::NotFullyProven,
            'That registration has not proven both an email address and a phone number.',
        );
    }

    public static function emailAlreadyRegistered(): self
    {
        return new self(
            RefusalReason::EmailAlreadyRegistered,
            'An account already holds that email address.',
        );
    }

    public static function phoneAlreadyRegistered(): self
    {
        return new self(
            RefusalReason::PhoneAlreadyRegistered,
            'An account already holds that phone number.',
        );
    }
}
