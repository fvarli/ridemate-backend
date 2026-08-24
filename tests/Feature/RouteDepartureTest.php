<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Routes\DepartureState;
use App\Routes\Recurrence;
use App\Routes\RouteDeparture;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A departure is a plan read in a stated timezone, and both halves matter.
 */
final class RouteDepartureTest extends TestCase
{
    private function pilotTimezone(): string
    {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return $timezone;
    }

    public function test_the_pilot_timezone_comes_from_configuration(): void
    {
        self::assertSame('Europe/Istanbul', $this->pilotTimezone());
    }

    // ------------------------------------------------------------ strictness

    /**
     * CARRIES WEIGHT. A rollover is a different journey, not a typo fixed.
     *
     * February the 30th is not a date. A permissive parser turns it into March
     * the 2nd and stores a journey on a day nobody chose.
     */
    public function test_an_impossible_calendar_date_is_refused(): void
    {
        $wronglyAccepted = [];

        foreach (['2026-02-30', '2026-13-01', '2026-00-10', '2026-1-5', '10-05-2026', ''] as $date) {
            try {
                RouteDeparture::fromInput(Recurrence::Once, $date, '08:00', $this->pilotTimezone());
                $wronglyAccepted[] = $date;
            } catch (InvalidArgumentException) {
                // Refused, which is the point.
            }
        }

        self::assertSame([], $wronglyAccepted);
    }

    public function test_an_impossible_wall_clock_time_is_refused(): void
    {
        $wronglyAccepted = [];

        foreach (['25:00', '08:60', '8:00', '08:00:00', 'morning', ''] as $time) {
            try {
                RouteDeparture::fromInput(Recurrence::Once, '2099-01-01', $time, $this->pilotTimezone());
                $wronglyAccepted[] = $time;
            } catch (InvalidArgumentException) {
                // Refused, which is the point.
            }
        }

        self::assertSame([], $wronglyAccepted);
    }

    public function test_a_real_date_and_time_are_accepted(): void
    {
        $departure = RouteDeparture::fromInput(
            Recurrence::Once,
            '2026-02-28',
            '08:25',
            $this->pilotTimezone(),
        );

        self::assertSame('2026-02-28', $departure->date);
        self::assertSame('08:25', $departure->time);
    }

    // -------------------------------------------------------------- the shape

    public function test_a_one_off_route_requires_a_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RouteDeparture::fromInput(Recurrence::Once, null, '08:00', $this->pilotTimezone());
    }

    public function test_a_recurring_route_refuses_a_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RouteDeparture::fromInput(Recurrence::Weekdays, '2099-01-01', '08:00', $this->pilotTimezone());
    }

    public function test_a_recurring_route_still_requires_a_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RouteDeparture::fromInput(Recurrence::Weekdays, null, '', $this->pilotTimezone());
    }

    // ------------------------------------------------------- past and future

    /**
     * CARRIES WEIGHT. The timezone is not decoration.
     *
     * Istanbul runs three hours ahead of UTC, so a wall clock read as UTC lands
     * three hours LATER than the same wall clock read in Istanbul. A departure
     * can therefore be safely in the past for the driver while a server that
     * forgot the zone still believes it is coming.
     *
     * Frozen at 09:30 UTC, which is 12:30 in Istanbul. A 12:00 departure has
     * gone; read as UTC it would look half an hour away.
     */
    public function test_a_departure_past_in_istanbul_is_past_even_though_utc_says_otherwise(): void
    {
        $now = CarbonImmutable::parse('2026-06-15T09:30:00Z');

        $departure = RouteDeparture::fromInput(
            Recurrence::Once,
            '2026-06-15',
            '12:00',
            'Europe/Istanbul',
        );

        self::assertSame(DepartureState::Past, $departure->state($now));
        self::assertFalse($departure->isUpcoming($now));

        // The mistake this guards against, made explicit: the same wall clock
        // read as UTC is still ahead of now.
        $naive = RouteDeparture::fromInput(Recurrence::Once, '2026-06-15', '12:00', 'UTC');
        self::assertSame(DepartureState::Upcoming, $naive->state($now));
    }

    public function test_a_departure_still_ahead_in_istanbul_is_upcoming(): void
    {
        $now = CarbonImmutable::parse('2026-06-15T09:30:00Z');

        $departure = RouteDeparture::fromInput(
            Recurrence::Once,
            '2026-06-15',
            '18:00',
            'Europe/Istanbul',
        );

        self::assertSame(DepartureState::Upcoming, $departure->state($now));
    }

    /**
     * A recurring journey has no single departure to have passed.
     */
    public function test_a_recurring_route_is_always_upcoming(): void
    {
        $departure = RouteDeparture::fromInput(
            Recurrence::Weekdays,
            null,
            '08:00',
            $this->pilotTimezone(),
        );

        self::assertNull($departure->instant());
        self::assertSame(
            DepartureState::Upcoming,
            $departure->state(CarbonImmutable::parse('2099-01-01T00:00:00Z')),
        );
    }

    /**
     * Pins what happens in a zone that does observe DST.
     *
     * Türkiye does not today, so this is not the pilot's behaviour — it is the
     * answer to "what would happen if the configured zone ever changed", pinned
     * now rather than discovered later. PHP resolves a wall clock inside a
     * spring-forward gap by moving it past the gap; the point is that it
     * resolves deterministically and does not throw.
     */
    public function test_a_nonexistent_local_time_resolves_deterministically(): void
    {
        $departure = RouteDeparture::fromInput(
            Recurrence::Once,
            '2026-03-29',
            '02:30',
            'Europe/Berlin',
        );

        $instant = $departure->instant();

        self::assertInstanceOf(CarbonImmutable::class, $instant);
        self::assertSame('2026-03-29T01:30:00+00:00', $instant->utc()->toAtomString());
    }

    // ------------------------------------------------------------ the route id

    /**
     * The client mints the id, so the server checks the version it minted.
     *
     * Laravel validates a UUID's version natively, which is why no rule object
     * is written here: a second definition of "is this a v7" would be a second
     * thing to keep correct.
     */
    public function test_only_a_version_7_uuid_is_accepted_as_a_route_id(): void
    {
        $v7 = '01991a00-0000-7000-8000-00000000abcd';
        $v4 = '9f1b7f4e-6c2a-4a5e-8f3d-2b1c4d5e6f70';

        self::assertTrue(Validator::make(['id' => $v7], ['id' => 'uuid:7'])->passes());
        self::assertFalse(Validator::make(['id' => $v4], ['id' => 'uuid:7'])->passes());
        self::assertFalse(Validator::make(['id' => 'not-a-uuid'], ['id' => 'uuid:7'])->passes());

        // And the weaker rule would have let the v4 through, which is the whole
        // reason the version is named.
        self::assertTrue(Validator::make(['id' => $v4], ['id' => 'uuid'])->passes());
    }
}
