<?php

declare(strict_types=1);

namespace App\Routes;

use App\Support\KeysetCursor;
use Carbon\CarbonImmutable;

/**
 * A position in a keyset-paginated list of routes.
 *
 * WHY IT IS ENCRYPTED RATHER THAN ENCODED
 *
 * The contract calls this opaque, and base64 is not opaque — it is an
 * invitation. A client that could read `created_at|id` out of a cursor would
 * eventually build one, and from then on the ordering of a query nobody
 * documented would be part of the public API. Encrypting it makes the promise
 * true instead of aspirational, and Laravel's encrypter authenticates as well
 * as hides, so a tampered cursor is refused rather than half-parsed.
 *
 * WHAT IT CARRIES, AND WHAT IT DOES NOT
 *
 * A version tag and the keyset tuple. No account id, no phone number, no place
 * label: every listing query is scoped by its own predicates regardless, so a
 * cursor is a position rather than a capability, and handing one to somebody
 * else would let them page through a list they could already read.
 *
 * The version tag is what makes a change of shape fail closed. A cursor issued
 * by an older or newer format decodes to the wrong arity or the wrong prefix
 * and is refused, rather than being read as a tuple that happens to fit.
 *
 * IT ALSO SEPARATES THE SURFACES
 *
 * Two lists share this shape — a member's own routes, and discovery — and each
 * names its own version. That is not decoration: the two are ordered by the
 * same tuple but filtered completely differently, so a cursor from one would
 * resume the other at a position that is arithmetically valid and semantically
 * meaningless. Naming the surface makes that a clean refusal instead.
 */
final readonly class RouteCursor
{
    /**
     * My Routes pages a member's own journeys.
     */
    public const MY_ROUTES = 'rm.myroutes.v1';

    /** Discovery pages the same tuple over a different set of rows. */
    public const DISCOVERY = 'rm.discovery.v1';

    public function __construct(
        public CarbonImmutable $createdAt,
        public string $id,
        public string $version = self::MY_ROUTES,
    ) {}

    public function encode(): string
    {
        return $this->cursor()->encode();
    }

    /** Null for anything unusable — see `App\Support\KeysetCursor`. */
    public static function decode(string $cursor, string $version = self::MY_ROUTES): ?self
    {
        $decoded = KeysetCursor::decode($cursor, $version);

        return $decoded instanceof KeysetCursor
            ? new self($decoded->createdAt, $decoded->id, $decoded->version)
            : null;
    }

    private function cursor(): KeysetCursor
    {
        return new KeysetCursor($this->createdAt, $this->id, $this->version);
    }
}
