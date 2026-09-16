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
 * ONLY SMS IS OPERATIONAL
 *
 * `Email` is declared and stored and nothing issues one. There is no email
 * sender, no email endpoint and no email column on an account, so a challenge
 * on this channel cannot currently be requested or verified through the API.
 * The case exists so that the storage, the indexes and the isolation between
 * channels are real and tested now rather than asserted later.
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
     * Declared, stored, isolated and tested — and unreachable from the API
     * until an email sender and the endpoints that use it exist.
     */
    case Email = 'email';
}
