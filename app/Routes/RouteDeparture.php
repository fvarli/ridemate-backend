<?php

declare(strict_types=1);

namespace App\Routes;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

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
     * Does this route run on that day?
     *
     * The one authority on the question, so recurrence membership cannot come
     * to mean one thing to seat requests and another to trips.
     *
     *   `once`     the day it was published for, and no other.
     *   `weekdays` Monday to Friday.
     *
     * WEEKDAY IS SPELLED OUT RATHER THAN ASKED OF CARBON
     *
     * `isWeekday()` consults `Carbon::getWeekendDays()`, a process-wide setting
     * any other code could change. What counts as a weekday is a product rule,
     * not a locale preference, so it is written here in ISO days — 1 Monday
     * through 5 Friday — and moves only when somebody decides it should.
     *
     * There is no holiday calendar and no exception list. A public holiday is
     * still a weekday to this product; whether a driver runs that morning is
     * theirs to say by starting the journey or not.
     *
     * Only the calendar day of [$serviceDate] is read. A time of day, if one
     * rides along, says nothing about which day was meant.
     */
    public function runsOn(CarbonImmutable $serviceDate): bool
    {
        $day = $serviceDate->format(self::DATE_FORMAT);

        if ($this->recurrence === Recurrence::Once) {
            return $day === $this->date;
        }

        $iso = $this->atMidnight($day)->dayOfWeekIso;

        return $iso >= 1 && $iso <= 5;
    }

    /**
     * The moment the journey on that day departs.
     *
     * The date is the caller's, the wall clock and the zone are the route's.
     * Which is the whole point: a plan has no single instant, but a plan on a
     * named day has exactly one.
     *
     * Refuses a day the route does not run on rather than answering for it. A
     * departure computed for a Saturday that no journey exists on is not a
     * useful value — it is a wrong one that would be compared against a clock
     * and believed, so the mistake is raised where it was made.
     *
     * @throws LogicException when the route does not run on [$serviceDate].
     */
    public function instantOn(CarbonImmutable $serviceDate): CarbonImmutable
    {
        if (! $this->runsOn($serviceDate)) {
            throw new LogicException(sprintf(
                'This %s route does not run on %s, so that day has no departure.',
                $this->recurrence->value,
                $serviceDate->format(self::DATE_FORMAT),
            ));
        }

        $instant = CarbonImmutable::createFromFormat(
            '!'.self::DATE_FORMAT.' '.self::TIME_FORMAT,
            $serviceDate->format(self::DATE_FORMAT).' '.$this->time,
            new DateTimeZone($this->timezone),
        );

        // Proven a real date and a real time by fromInput(); this satisfies the
        // type rather than handling a case.
        assert($instant instanceof CarbonImmutable);

        return $instant;
    }

    /**
     * The calendar date it is now, where this route is read.
     *
     * The single answer to "what is today", because a horizon measured against
     * the server's idea of the day would move a member's deadline by however
     * far the deployment happens to be from the pilot.
     *
     * A bare date, so it compares with a service date as a day rather than as
     * a moment.
     */
    public function localDate(?CarbonImmutable $now = null): CarbonImmutable
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone(new DateTimeZone($this->timezone));

        return $this->atMidnight($local->format(self::DATE_FORMAT));
    }

    /**
     * Whole calendar days from this route's today to that day.
     *
     * Negative for a day already gone, zero for today. Both sides are read as
     * bare days in the route's own zone, so a partial day cannot round the
     * answer and a process running elsewhere cannot shift it.
     *
     * The arithmetic lives here, with the timezone it depends on, rather than
     * in whichever rule happens to need a distance this week.
     */
    public function daysUntil(CarbonImmutable $serviceDate, ?CarbonImmutable $now = null): int
    {
        return (int) $this->localDate($now)->diffInDays(
            $this->atMidnight($serviceDate->format(self::DATE_FORMAT)),
            absolute: false,
        );
    }

    /**
     * Has the journey on that day already left?
     *
     * The dated form of the question `state()` answers for a one-off route, and
     * the primitive both the request window and the trip's start window are
     * built from — so neither gets to decide separately what "already left"
     * means. Inclusive: the departure instant itself has been reached.
     */
    public function hasDeparted(CarbonImmutable $serviceDate, ?CarbonImmutable $now = null): bool
    {
        return ! $this->instantOn($serviceDate)->greaterThan($now ?? CarbonImmutable::now());
    }

    /**
     * The moment this departure happens, for a one-off route.
     *
     * Null for a recurring one, which is the honest answer rather than the next
     * occurrence: choosing "the next Tuesday" here would invent a schedule the
     * product has not designed.
     *
     * Backed by `instantOn` rather than computing its own, so a one-off journey
     * and a dated one cannot drift into two arithmetics.
     */
    public function instant(): ?CarbonImmutable
    {
        $date = $this->dateValue();

        return $date === null ? null : $this->instantOn($date);
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
     * A `Y-m-d` read as a bare day in this route's zone.
     *
     * `!` resets everything the format does not mention, so no time of day
     * leaks in from the clock.
     */
    private function atMidnight(string $day): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat(
            '!'.self::DATE_FORMAT,
            $day,
            new DateTimeZone($this->timezone),
        );

        assert($date instanceof CarbonImmutable);

        return $date;
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
