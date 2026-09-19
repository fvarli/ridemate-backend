<?php

declare(strict_types=1);

namespace App\Otp\Email;

/**
 * The default, and it fails loudly.
 *
 * RideMate has selected no email provider. The tempting default is a sender
 * that quietly discards so nothing breaks — and the result would be the same
 * failure the SMS seam already refuses: a deployment where delivery appears to
 * work, nobody receives anything, and the logs are clean because as far as the
 * code is concerned nothing went wrong.
 *
 * Laravel's own `config/mail.php` is not a substitute. It is framework
 * skeleton nothing in this application reads, and defaulting to it would mean
 * passcodes going wherever `MAIL_MAILER` happened to point — in the stock
 * configuration, the log.
 */
final class NullEmailSender implements EmailSender
{
    public function sendPasscode(string $emailAddress, string $code): void
    {
        throw new EmailDeliveryFailed('No email adapter is configured.');
    }
}
