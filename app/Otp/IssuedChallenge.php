<?php

declare(strict_types=1);

namespace App\Otp;

/**
 * The only moment a passcode exists in plaintext server-side.
 *
 * Returned from the issuing transaction, handed to the sender, and dropped. It
 * is never persisted, never logged, never attached to a model and never placed
 * in exception or request context.
 *
 * It carries the channel as well as the destination so a caller knows which
 * sender the code is for without inferring it from the shape of a string.
 */
final readonly class IssuedChallenge
{
    public function __construct(
        public string $id,
        public OtpChannel $channel,
        public string $destination,
        public string $code,
    ) {}
}
