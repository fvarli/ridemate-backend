<?php

declare(strict_types=1);

namespace App\Journeys;

use App\Routes\Recurrence;
use App\Routes\RouteDeparture;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * One row of the candidate scan, read as values rather than as a database row.
 *
 * The scan does not hydrate models — a page's routes and trips are fetched once
 * the page is settled, not per candidate examined — so what comes back is a
 * bare row of columns. This is where it stops being that: every column is
 * checked and converted here, and the rest of `ListMyJourneys` deals only in a
 * date, an id, a departure and a flag.
 *
 * The departure is rebuilt as a `RouteDeparture` rather than kept as loose
 * columns, so the question the scan asks it — does this route run on this day —
 * is answered by the class that owns recurrence and by nothing else.
 */
final readonly class JourneyCandidate
{
    public function __construct(
        public string $routeId,
        public CarbonImmutable $serviceDate,
        public RouteDeparture $departure,
        public bool $isRunning,
    ) {}

    public static function fromRow(object $row): self
    {
        /** @var array<string, mixed> $columns */
        $columns = (array) $row;

        $day = self::day(self::text($columns, 'service_date'));
        $date = self::optionalText($columns, 'departure_date');

        return new self(
            self::text($columns, 'route_id'),
            $day,
            RouteDeparture::fromInput(
                Recurrence::from(self::text($columns, 'recurrence')),
                // PostgreSQL hands a `date` back with no time; the domain reads
                // a bare `Y-m-d`, so anything a driver appended is cut rather
                // than parsed.
                $date === null ? null : substr($date, 0, 10),
                // `08:00:00` from the column, `08:00` in the domain.
                substr(self::text($columns, 'departure_time'), 0, 5),
                self::text($columns, 'timezone'),
            ),
            (bool) ($columns['is_running'] ?? false),
        );
    }

    /** The position this candidate occupies in the feed's ordering. */
    public function position(): JourneyCursor
    {
        return new JourneyCursor($this->serviceDate, $this->routeId);
    }

    private static function day(string $value): CarbonImmutable
    {
        $date = ServiceDate::parse(substr($value, 0, 10));

        if (! $date instanceof CarbonImmutable) {
            // The value came from a `date` column or from a `::date` cast, so
            // this is a fault rather than a case: something changed the query's
            // shape without changing this.
            throw new RuntimeException("A candidate carried `$value`, which is not a service date.");
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private static function text(array $columns, string $column): string
    {
        $value = $columns[$column] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("The candidate scan returned no `$column`.");
        }

        return $value;
    }

    /**
     * A column that is genuinely nullable: a recurring plan has no departure
     * date, and that is the shape of the row rather than a missing value.
     *
     * @param  array<string, mixed>  $columns
     */
    private static function optionalText(array $columns, string $column): ?string
    {
        $value = $columns[$column] ?? null;

        return is_string($value) ? $value : null;
    }
}
