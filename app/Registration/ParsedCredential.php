<?php

declare(strict_types=1);

namespace App\Registration;

/**
 * A registration credential that survived parsing: a registration id, and the
 * secret to check against that row.
 *
 * Its existence means the shape was valid. It says nothing about whether the
 * registration exists, the secret matches, or the registration may still be
 * advanced.
 */
final readonly class ParsedCredential
{
    public function __construct(
        public string $registrationId,
        public string $secret,
    ) {}
}
