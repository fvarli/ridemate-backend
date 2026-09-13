<?php

declare(strict_types=1);

namespace App\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A position in the driver's journey feed, ordered `(service_date desc,
 * route_id desc)`.
 *
 * WHY IT DOES NOT DELEGATE TO `App\Support\KeysetCursor`
 *
 * That class pages `(created_at, id)` and stores the first as an instant, to
 * microseconds and with an offset, because that is what the column is. A
 * service date is a calendar day in the route's own zone and has no time of
 * day. Encoding it through an instant would attach a midnight in some zone and
 * make two cursors that name the same day differ — the same conflation the
 * `date` columns exist to avoid. So this carries a date as a date.
 *
 * Everything else about it is deliberately identical to its sibling: encrypted
 * rather than encoded, so "opaque" is true instead of aspirational; a version
 * tag naming this surface, so a cursor from another feed is refused rather than
 * read as a tuple that happens to fit; and no identity of any kind inside it,
 * because the query it resumes is re-authorized on the request that carries it.
 */
final readonly class JourneyCursor
{
    /** The driver's own dated journeys. */
    public const VERSION = 'rm.journeys.v1';

    private const SEPARATOR = '|';

    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(
        public CarbonImmutable $serviceDate,
        public string $routeId,
    ) {}

    public function encode(): string
    {
        return Crypt::encryptString(implode(self::SEPARATOR, [
            self::VERSION,
            $this->serviceDate->format(self::DATE_FORMAT),
            $this->routeId,
        ]));
    }

    /**
     * Null for anything unusable: tampered, wrong surface, wrong shape, not a
     * real date. The caller answers 422 on the cursor field and says only that
     * it is not usable — describing why would describe the format.
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

        [, $day, $routeId] = $parts;

        if (! Str::isUuid($routeId)) {
            return null;
        }

        $date = ServiceDate::parse($day);

        return $date instanceof CarbonImmutable ? new self($date, $routeId) : null;
    }
}
