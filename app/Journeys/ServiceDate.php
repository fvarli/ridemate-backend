<?php

declare(strict_types=1);

namespace App\Journeys;

use App\Routes\RouteDeparture;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A `YYYY-MM-DD` read as a calendar day, or refused.
 *
 * STRICT, FOR THE REASON `RouteDeparture` IS
 *
 * `2026-02-30` is not a date. Left to a permissive parser it becomes March the
 * 2nd — accepted, addressed, and pointing at a journey nobody asked for. The
 * parse is checked by formatting the result back and requiring it to equal what
 * arrived, which is the only way to tell "parsed" from "parsed into something
 * else". The format string is `RouteDeparture`'s, so a day in a path and a day
 * in a column cannot come to be spelled differently.
 *
 * No time of day and no zone meaning: it is a day, and which zone that day is
 * read in belongs to the route it is asked about.
 */
final class ServiceDate
{
    private function __construct() {}

    /** Null when the value is not a real calendar day in `YYYY-MM-DD`. */
    public static function parse(string $value): ?CarbonImmutable
    {
        $format = RouteDeparture::DATE_FORMAT;

        $parsed = DateTimeImmutable::createFromFormat(
            '!'.$format,
            $value,
            new DateTimeZone('UTC'),
        );

        if ($parsed === false || $parsed->format($format) !== $value) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!'.$format, $value);

        return $date instanceof CarbonImmutable ? $date : null;
    }
}
