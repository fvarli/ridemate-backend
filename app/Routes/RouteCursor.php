<?php

declare(strict_types=1);

namespace App\Routes;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A position in a member's own list of routes.
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
 * label: the listing query is scoped to the caller regardless, so a cursor is a
 * position rather than a capability, and handing one to somebody else would let
 * them page through their OWN list from an odd offset and nothing more.
 *
 * The version tag is what makes a change of shape fail closed. A cursor issued
 * by an older or newer format decodes to the wrong arity or the wrong prefix
 * and is refused, rather than being read as a tuple that happens to fit.
 */
final readonly class RouteCursor
{
    /**
     * Bumped when the tuple changes. An old cursor then fails cleanly instead
     * of being reinterpreted under new rules.
     */
    private const VERSION = 'rm.myroutes.v1';

    private const SEPARATOR = '|';

    /**
     * Microseconds included on purpose.
     *
     * Two routes published in the same second are ordered by their id, but two
     * published in the same MICROSECOND would be ambiguous if the cursor stored
     * anything coarser — the resume point would sit between rows and one of
     * them would be skipped.
     */
    private const INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public CarbonImmutable $createdAt,
        public string $id,
    ) {}

    public function encode(): string
    {
        return Crypt::encryptString(implode(self::SEPARATOR, [
            self::VERSION,
            $this->createdAt->format(self::INSTANT_FORMAT),
            $this->id,
        ]));
    }

    /**
     * The position a cursor names, or null if it does not name one.
     *
     * Null covers every way this can go wrong — tampered, truncated, encrypted
     * under a different key, issued by another version of this format, or
     * simply not a cursor. The caller turns that into a validation failure,
     * because a bad cursor is a bad request and not a server fault.
     */
    public static function decode(string $cursor): ?self
    {
        try {
            $plain = Crypt::decryptString($cursor);
        } catch (DecryptException) {
            return null;
        }

        $parts = explode(self::SEPARATOR, $plain);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        [, $instant, $id] = $parts;

        if (! Str::isUuid($id)) {
            return null;
        }

        $createdAt = CarbonImmutable::createFromFormat(self::INSTANT_FORMAT, $instant);

        if (! $createdAt instanceof CarbonImmutable) {
            return null;
        }

        return new self($createdAt, $id);
    }
}
