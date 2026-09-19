<?php

declare(strict_types=1);

namespace App\Registration;

use Carbon\CarbonImmutable;

/**
 * The only moment a registration credential exists in plaintext server-side.
 *
 * Returned from minting, handed to the caller, and dropped. It is never
 * persisted, never logged, never attached to a model and never placed in
 * exception or request context — only its hash reaches the database.
 *
 * The registration id travels alongside it, because that is the one value about
 * a registration that IS safe to log, and a caller that had to split the
 * credential apart to correlate anything would be handling a secret to get at a
 * non-secret.
 */
final readonly class MintedRegistration
{
    public function __construct(
        public string $registrationId,
        public string $credential,
        public CarbonImmutable $expiresAt,
    ) {}
}
