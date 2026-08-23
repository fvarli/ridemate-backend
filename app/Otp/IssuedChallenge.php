<?php

declare(strict_types=1);

namespace App\Otp;

/**
 * The only moment a passcode exists in plaintext server-side.
 *
 * Returned from the issuing transaction, handed to the sender, and dropped. It
 * is never persisted, never logged, never attached to a model and never placed
 * in exception or request context.
 */
final readonly class IssuedChallenge
{
    public function __construct(
        public string $id,
        public string $phoneE164,
        public string $code,
    ) {}
}
