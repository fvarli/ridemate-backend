<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * A credential that survived parsing: a token row id, and the secret to check
 * against that row.
 *
 * Its existence means the shape was valid. It says nothing about whether the
 * row exists, the secret matches, or the session is live.
 */
final readonly class ParsedToken
{
    public function __construct(
        public string $id,
        public string $secret,
    ) {}
}
