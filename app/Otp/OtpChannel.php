<?php

declare(strict_types=1);

namespace App\Otp;

/**
 * Where a one-time passcode is delivered.
 *
 * WHY THIS EXISTS BEFORE EMAIL DOES
 *
 * A challenge used to be a phone number and a code, and the column was called
 * `phone_e164`. That name was the whole model: one destination kind, so no
 * discriminator was needed. RideMate's account model is becoming one where an
 * email address and a phone number are two independently proven possessions,
 * and a passcode will be delivered to either. The discriminator has to exist
 * before the second channel does, or the second channel arrives as a schema
 * change in the middle of an authentication flow.
 *
 * ONLY SMS IS PUBLICLY REACHABLE
 *
 * Both cases are issued and verified by application code. Only SMS is exposed:
 * `POST /api/v1/auth/otp` delivers on `Sms`, and there is no counterpart for
 * `Email` — no route, no controller, and no email column on an account. An
 * email passcode is an internal capability that can prove possession of an
 * address, built and tested before the slice that decides what proving it
 * entitles anyone to.
 *
 * NOT A FACTOR, AND NOT A PLACE TO PUT TOTP
 *
 * This names how a code REACHES someone. A time-based authenticator code is
 * never delivered — it is derived from a shared secret on a device nobody
 * sends anything to — so it is not a member of this enum and adding it here
 * would be modelling a destination for something that has none. When
 * authenticator support arrives it belongs beside this concept, not inside it.
 */
enum OtpChannel: string
{
    /**
     * A text message to an E.164 number.
     *
     * The only channel any code path issues today. Its destination is
     * normalized once, at the request boundary, by `App\Support\PhoneNumber`.
     */
    case Sms = 'sms';

    /**
     * A message to an email address.
     *
     * Issued by `SendEmailPasscode` and verified by `VerifyEmailPasscode`,
     * whose destinations are normalized once by `App\Support\EmailAddress`.
     * Unreachable from the API: nothing public issues one, and in production
     * the bound sender refuses because no provider has been selected.
     */
    case Email = 'email';
}
