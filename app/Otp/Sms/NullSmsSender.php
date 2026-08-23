<?php

declare(strict_types=1);

namespace App\Otp\Sms;

/**
 * The default, and it fails loudly.
 *
 * RideMate has no production SMS provider. The tempting default is a sender
 * that quietly discards, so nothing breaks — and the result is a deployment
 * where sign-in appears to work, members never receive anything, and the logs
 * are clean because nothing went wrong as far as the code is concerned.
 *
 * Refusing is the honest behaviour: the operator finds out at the first
 * request rather than from a member who cannot get in.
 */
final class NullSmsSender implements SmsSender
{
    public function sendPasscode(string $phoneE164, string $code): void
    {
        throw new SmsDeliveryFailed('No SMS adapter is configured.');
    }
}
