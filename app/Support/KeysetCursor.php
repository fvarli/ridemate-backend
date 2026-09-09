<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A position in a feed ordered by `(created_at desc, id desc)`.
 *
 * Extracted from `App\Routes\RouteCursor` when seat requests became the third
 * and fourth surface to page the same tuple. Copying sixty lines of encryption
 * and parsing would have been a second pagination implementation to keep
 * correct, and a third would have followed; `RouteCursor` now delegates here
 * and keeps its own surface tags.
 *
 * OPAQUE MEANS ENCRYPTED
 *
 * Not merely encoded — a base64 of the sort key would make the word false the
 * moment somebody looked at one. It carries no account id, no phone and no
 * label, and every query it resumes is re-authorized on the request that uses
 * it, so a cursor is a position rather than a capability.
 *
 * THE VERSION TAG NAMES THE SURFACE
 *
 * Four feeds now order by this same tuple. Without a tag, a cursor from one
 * would decode cleanly on another and resume from a position established
 * somewhere else entirely — a passenger's own history paging into a driver's
 * incoming requests. A cursor from the wrong surface is refused rather than
 * decoded, and either surface can change its ordering later without the other's
 * saved cursors quietly meaning something new.
 */
final readonly class KeysetCursor
{
    private const SEPARATOR = '|';

    /** Microsecond precision with the offset, so a resumed position is exact. */
    private const INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public CarbonImmutable $createdAt,
        public string $id,
        public string $version,
    ) {}

    public function encode(): string
    {
        return Crypt::encryptString(implode(self::SEPARATOR, [
            $this->version,
            $this->createdAt->format(self::INSTANT_FORMAT),
            $this->id,
        ]));
    }

    /**
     * Null for anything unusable: tampered, wrong version, wrong surface, wrong
     * shape. The caller answers 422 on the cursor field and says only that it
     * is not usable — describing why would describe the format.
     */
    public static function decode(string $cursor, string $version): ?self
    {
        try {
            $plain = Crypt::decryptString($cursor);
        } catch (DecryptException) {
            return null;
        }

        $parts = explode(self::SEPARATOR, $plain);

        if (count($parts) !== 3 || $parts[0] !== $version) {
            return null;
        }

        [, $instant, $id] = $parts;

        if (! Str::isUuid($id)) {
            return null;
        }

        $createdAt = CarbonImmutable::createFromFormat(self::INSTANT_FORMAT, $instant);

        return $createdAt instanceof CarbonImmutable
            ? new self($createdAt, $id, $version)
            : null;
    }
}
