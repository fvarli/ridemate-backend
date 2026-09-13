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
    // ------------------------------------------- which days a route runs on

    private function weekdayPlan(string $timezone = 'Europe/Istanbul'): RouteDeparture
    {
        return RouteDeparture::fromInput(Recurrence::Weekdays, null, '08:00', $timezone);
    }

    private function oneOff(string $date, string $timezone = 'Europe/Istanbul'): RouteDeparture
    {
        return RouteDeparture::fromInput(Recurrence::Once, $date, '08:00', $timezone);
    }

    private function day(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        self::assertInstanceOf(CarbonImmutable::class, $parsed);

        return $parsed;
    }

    public function test_a_one_off_route_runs_only_on_its_own_date(): void
    {
        $departure = $this->oneOff('2026-09-14');

        self::assertTrue($departure->runsOn($this->day('2026-09-14')));
    }

    public function test_a_one_off_route_does_not_run_on_another_date(): void
    {
        $departure = $this->oneOff('2026-09-14');

        self::assertFalse($departure->runsOn($this->day('2026-09-15')));
        self::assertFalse($departure->runsOn($this->day('2026-09-13')));
    }

    /**
     * CARRIES WEIGHT. A weekday is Monday to Friday, and nothing else.
     *
     * 2026-09-14 is a Monday and 2026-09-18 a Friday; 2026-09-19 and -20 are
     * the weekend. Each is its own case, so a rule that let Saturday through
     * cannot hide behind a passing Monday.
     */
    public function test_a_weekday_plan_runs_on_monday(): void
    {
        self::assertTrue($this->weekdayPlan()->runsOn($this->day('2026-09-14')));
    }

    public function test_a_weekday_plan_runs_on_friday(): void
    {
        self::assertTrue($this->weekdayPlan()->runsOn($this->day('2026-09-18')));
    }

    public function test_a_weekday_plan_does_not_run_on_saturday(): void
    {
        self::assertFalse($this->weekdayPlan()->runsOn($this->day('2026-09-19')));
    }

    public function test_a_weekday_plan_does_not_run_on_sunday(): void
    {
        self::assertFalse($this->weekdayPlan()->runsOn($this->day('2026-09-20')));
    }

    /** A public holiday is still a weekday: nothing here consults a calendar. */
    public function test_a_public_holiday_is_still_a_weekday(): void
    {
        // 2026-10-29, Republic Day in Türkiye, falls on a Thursday.
        self::assertTrue($this->weekdayPlan()->runsOn($this->day('2026-10-29')));
    }

    // ------------------------------------------ the instant of a dated journey

    /**
     * CARRIES WEIGHT. The supplied day is the one that is used.
     *
     * A dated instant that ignored its argument would answer the same moment
     * for every journey of a plan, and every window built on it would be wrong
     * in the same direction.
     */
    public function test_the_dated_instant_follows_the_day_it_was_given(): void
    {
        $plan = $this->weekdayPlan();

        self::assertSame(
            '2026-09-14T08:00:00+03:00',
            $plan->instantOn($this->day('2026-09-14'))->toIso8601String(),
        );
        self::assertSame(
            '2026-09-15T08:00:00+03:00',
            $plan->instantOn($this->day('2026-09-15'))->toIso8601String(),
        );
    }

    /** A day the route does not run on has no departure to compute. */
    public function test_a_day_the_route_does_not_run_on_has_no_instant(): void
    {
        $this->expectException(\LogicException::class);

        $this->weekdayPlan()->instantOn($this->day('2026-09-19'));
    }

    /** The one-off form is the dated form, not a second arithmetic. */
    public function test_the_one_off_instant_is_the_dated_instant(): void
    {
        $departure = $this->oneOff('2026-09-14');

        self::assertEquals(
            $departure->instantOn($this->day('2026-09-14')),
            $departure->instant(),
        );
    }

    /**
     * CARRIES WEIGHT. The zone is applied per date, not as a fixed offset.
     *
     * Britain changes clocks on 2026-03-29, so 08:00 is GMT the day before and
     * BST the day after. A departure built from a stored offset — or from the
     * server's zone — would be an hour out on one side of that Sunday, and the
     * route's own timezone is the only thing that gets it right on both.
     *
     * `Europe/London` is used deliberately: the pilot configuration is not
     * touched to prove a timezone rule.
     */
    public function test_the_route_timezone_is_applied_per_date_across_a_dst_change(): void
    {
        $plan = $this->weekdayPlan('Europe/London');

        self::assertSame(
            '2026-03-27T08:00:00+00:00',
            $plan->instantOn($this->day('2026-03-27'))->toIso8601String(),
        );
        self::assertSame(
            '2026-03-30T08:00:00+01:00',
            $plan->instantOn($this->day('2026-03-30'))->toIso8601String(),
        );
    }

    /** Departure is reached at the instant itself, not a moment later. */
    public function test_a_dated_departure_is_reached_inclusively(): void
    {
        $plan = $this->weekdayPlan();
        $monday = $this->day('2026-09-14');
        $departs = $plan->instantOn($monday);

        self::assertFalse($plan->hasDeparted($monday, $departs->subSecond()));
        self::assertTrue($plan->hasDeparted($monday, $departs));
        self::assertTrue($plan->hasDeparted($monday, $departs->addSecond()));
    }

    // ------------------------------------------------- the route's own today

    /**
     * CARRIES WEIGHT. Today is read where the route is, not where the server is.
     *
     * At 22:30 UTC it is already tomorrow in İstanbul. A horizon measured on
     * the server's day would give that member one day less than the one beside
     * them.
     */
    public function test_today_is_the_routes_local_day(): void
    {
        $plan = $this->weekdayPlan();
        $lateUtc = CarbonImmutable::parse('2026-09-14T22:30:00Z');

        self::assertSame('2026-09-15', $plan->localDate($lateUtc)->format('Y-m-d'));
        self::assertSame(0, $plan->daysUntil($this->day('2026-09-15'), $lateUtc));
        self::assertSame(-1, $plan->daysUntil($this->day('2026-09-14'), $lateUtc));
    }

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
