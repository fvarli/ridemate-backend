<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The only moment the plaintext credentials exist server-side.
 *
 * They are returned to the caller and then dropped. Nothing persists them,
 * logs them or stores them on a model — only their hashes reach the database.
 */
final readonly class IssuedTokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $sessionId,
        public int $expiresIn,
    ) {}
}
