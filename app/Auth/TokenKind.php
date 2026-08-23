<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The two credentials, and the two things that keep them apart.
 *
 * They live on the same row and are minted by the same act, so nothing about
 * the storage distinguishes them. These do:
 *
 *   The PREFIX is a routing and rejection aid. It lets the wrong credential be
 *   refused before any lookup happens, and it makes a leaked value
 *   identifiable in a bug report without anyone having to test it.
 *
 *   The HASH DOMAIN is the real defence. Hashing under different domains means
 *   a refresh secret cannot validate as an access token even if every prefix
 *   check were removed — the stored hashes simply do not match. One is a
 *   convenience; the other is a guarantee.
 *
 * The `.v1` in each domain is deliberate. Changing the hash construction later
 * means changing the domain, which invalidates every existing credential at
 * once rather than leaving two constructions silently accepted.
 */
enum TokenKind: string
{
    case Access = 'access';
    case Refresh = 'refresh';

    public function prefix(): string
    {
        return match ($this) {
            self::Access => 'rma_',
            self::Refresh => 'rmr_',
        };
    }

    public function hashDomain(): string
    {
        return match ($this) {
            self::Access => 'rm.access.v1:',
            self::Refresh => 'rm.refresh.v1:',
        };
    }
}
