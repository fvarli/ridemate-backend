<?php

declare(strict_types=1);

namespace App\Otp;

use App\Otp\Email\InvalidEmailAddress;
use App\Support\EmailAddress;

/**
 * Was this the right code for this address?
 *
 * WHAT IT ANSWERS, AND WHAT IT DELIBERATELY DOES NOT
 *
 * A bare yes or no about this instant. It creates no account, touches no
 * account, issues no access or refresh token, and produces nothing a client
 * could hold or present later. The only durable trace is `consumed_at` on the
 * challenge row, which is not addressable and not a credential.
 *
 * That is the entire honest content of an email passcode verification today.
 * A durable proof artifact — something a later registration step could redeem
 * — would have to answer how long it lives, whether it is single use, what it
 * is bound to and how it meets the phone proof that must accompany it. Those
 * are the questions the registration-state slice exists to answer, and
 * inventing a token here to make this return something more satisfying would
 * be answering them by accident.
 *
 * WHY IT EXISTS AS AN ACTION AT ALL
 *
 * So that the canonicalization used to verify is the same one used to issue.
 * A caller reaching OtpService directly would have to remember to normalize,
 * and a caller that forgot would look up a destination that was never written.
 *
 * Every failure is the same `false` — no challenge, expired, superseded,
 * attempts exhausted, simply wrong. `OtpService` cannot distinguish them and
 * neither can this.
 */
final class VerifyEmailPasscode
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * @throws InvalidEmailAddress when the destination is not an address.
     */
    public function __invoke(string $emailAddress, string $code): bool
    {
        // Throws rather than returning false, and the difference matters. A
        // wrong code is an ordinary outcome; an address that does not parse is
        // a caller that skipped its own validation, and answering it with the
        // same `false` would hide the bug behind a legitimate-looking refusal.
        $canonical = EmailAddress::normalize($emailAddress)
            ?? throw new InvalidEmailAddress('The destination is not a valid email address.');

        return $this->otp->verify(OtpChannel::Email, $canonical, $code);
    }
}
