<?php

declare(strict_types=1);

namespace App\Routes;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * When a route leaves, in the terms the driver actually chose.
 *
 * A ROUTE IS A PLAN, NOT AN INSTANT
 *
 * A weekday commute at 08:00 has no single moment to store: it happens again
 * tomorrow. So a departure is a wall clock — plus a date when, and only when,
 * the journey is a one-off — and the timezone it is read in.
 *
 * That timezone is the pilot's, resolved from configuration rather than taken
 * from the request. It is not a formatting preference: it decides whether a
 * departure has already passed, and a client that could name its own zone could
 * move its own deadline by three hours.
 *
 * STRICT, BECAUSE A ROLLOVER IS A DIFFERENT JOURNEY
 *
 * `2026-02-30` is not a date, and `25:00` is not a time. Left to a permissive
 * parser they become March the 2nd and one in the morning — accepted, stored,
 * and wrong. Both are rejected here instead: the parse is checked by formatting
 * the result back and requiring it to equal what arrived, which is the only way
 * to tell "parsed" from "parsed into something else".
 */
final readonly class RouteDeparture
{
    public const DATE_FORMAT = 'Y-m-d';

    public const TIME_FORMAT = 'H:i';

    private function __construct(
        public Recurrence $recurrence,
        public ?string $date,
        public string $time,
        public string $timezone,
    ) {}

    /**
     * @throws InvalidArgumentException when the shape or the calendar is wrong.
     */
    public static function fromInput(
        Recurrence $recurrence,
        ?string $date,
        string $time,
        string $timezone,
    ): self {
        if ($recurrence->requiresDepartureDate() && $date === null) {
            throw new InvalidArgumentException('A one-off route needs a departure date.');
        }

        if (! $recurrence->requiresDepartureDate() && $date !== null) {
            // Not a tidy-up. A recurring journey carrying a date would read as
            // happening on that date, and the next reader could not tell which
            // half to believe.
            throw new InvalidArgumentException('A recurring route has no departure date.');
        }

        if ($date !== null && ! self::isExactly($date, self::DATE_FORMAT)) {
            throw new InvalidArgumentException('The departure date is not a real date.');
        }

        if (! self::isExactly($time, self::TIME_FORMAT)) {
            throw new InvalidArgumentException('The departure time is not a real time.');
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('The timezone is not an IANA identifier.');
        }

        return new self($recurrence, $date, $time, $timezone);
    }

    /**
     * The calendar date, as a date rather than a string.
     *
     * No time of day and no zone meaning: it is the day a one-off journey
     * happens, which is exactly what the `date` column stores. Parsed with `!`
     * so nothing leaks in from the current clock.
     */
    public function dateValue(): ?CarbonImmutable
    {
        if ($this->date === null) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $this->date);

        // Already proven a real date by fromInput(); this satisfies the type.
        assert($date instanceof CarbonImmutable);

        return $date;
    }

    /**
     * The moment this departure happens, for a one-off route.
     *
     * Null for a recurring one, which is the honest answer rather than the next
     * occurrence: choosing "the next Tuesday" here would invent a schedule the
     * product has not designed.
     */
    public function instant(): ?CarbonImmutable
    {
        if ($this->date === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            '!'.self::DATE_FORMAT.' '.self::TIME_FORMAT,
            $this->date.' '.$this->time,
            new DateTimeZone($this->timezone),
        );
    }

    /**
     * Has this departure already happened?
     *
     * A recurring route is never past: there is always another weekday.
     */
    public function state(?CarbonImmutable $now = null): DepartureState
    {
        $instant = $this->instant();

        if ($instant === null) {
            return DepartureState::Upcoming;
        }

        $now ??= CarbonImmutable::now();

        return $instant->greaterThan($now)
            ? DepartureState::Upcoming
            : DepartureState::Past;
    }

    public function isUpcoming(?CarbonImmutable $now = null): bool
    {
        return $this->state($now) === DepartureState::Upcoming;
    }

    /**
     * Parsed, and parsed into the same thing it was given.
     *
     * `!` resets the fields the format does not mention, so a bare date does
     * not inherit the current time of day. The round-trip comparison is what
     * catches a value PHP was willing to interpret as a different moment —
     * February the 30th, or the 25th hour.
     */
    private static function isExactly(string $value, string $format): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format($format) === $value;
    }
}
