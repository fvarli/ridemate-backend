<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Route;
use App\Routes\Recurrence;
use App\Routes\RouteStatus;
use App\SeatRequests\RequestableJourney;
use App\SeatRequests\RequestableServiceDates;
use Carbon\CarbonImmutable;
use ReflectionNamedType;
use ReflectionParameter;
use Tests\TestCase;

/**
 * Which of a route's journeys may be asked about, all of them at once.
 *
 * WHAT THIS FILE IS DEFENDING
 *
 * Discovery publishes these dates so a client never has to work out what today
 * is where a route runs. That makes the list a contract about a timezone the
 * client cannot check, which is exactly the kind of answer that can be wrong
 * for months without anybody noticing: every date looks plausible, and the
 * member who is offered a departed Monday finds out from a `422`.
 *
 * So the cases below are mostly about edges — the departure instant, the far
 * end of the horizon, a weekend, a daylight-saving change, and the same moment
 * read in two different zones.
 *
 * NOT EUROPE/ISTANBUL ONLY, ON PURPOSE
 *
 * The pilot runs in one zone, which is precisely why a bug here would survive:
 * a fixed +03 and a real IANA lookup agree on every Istanbul date, all year,
 * because Türkiye has not observed daylight saving since 2016. The zone is
 * therefore a parameter of the fixture, and two cases deliberately run
 * elsewhere.
 *
 * The routes are built in memory. Nothing under test reads the database, and a
 * fixture that inserted rows would be testing the schema instead.
 */
final class RequestableServiceDatesTest extends TestCase
{
    /** A Monday. The plans below depart at 08:00 unless a case says otherwise. */
    private const MONDAY = '2026-09-14';

    private function at(string $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($instant);
    }

    private function day(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        self::assertInstanceOf(CarbonImmutable::class, $parsed);

        return $parsed;
    }

    private function route(
        Recurrence $recurrence,
        ?string $date,
        string $time = '08:00',
        string $timezone = 'Europe/Istanbul',
    ): Route {
        $route = new Route;
        $route->id = '01991c00-0000-7000-8000-000000000001';
        $route->recurrence = $recurrence;
        $route->departure_date = $date === null ? null : $this->day($date);
        $route->departure_time = $time.':00';
        $route->timezone = $timezone;
        $route->status = RouteStatus::Published;

        return $route;
    }

    private function plan(string $time = '08:00', string $timezone = 'Europe/Istanbul'): Route
    {
        return $this->route(Recurrence::Weekdays, null, $time, $timezone);
    }

    /**
     * @return list<string>
     */
    private function datesOf(Route $route, string $instant): array
    {
        return array_map(
            static fn (CarbonImmutable $day): string => $day->format('Y-m-d'),
            RequestableServiceDates::of($route, $this->at($instant)),
        );
    }

    // --------------------------------------------------------- today's edge

    public function test_todays_journey_is_offered_before_it_departs(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-09-14T07:59:59+03:00');

        self::assertSame(self::MONDAY, $dates[0]);
    }

    /**
     * The departure instant itself counts as gone.
     *
     * Not a choice made here: `RouteDeparture::hasDeparted` is inclusive, the
     * request path reads the same method, and a list that disagreed would offer
     * a member the one journey a create refuses at that exact second.
     */
    public function test_todays_journey_is_gone_at_the_departure_instant(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-09-14T08:00:00+03:00');

        self::assertNotContains(self::MONDAY, $dates);
        self::assertSame('2026-09-15', $dates[0]);
    }

    public function test_todays_journey_is_gone_after_it_departs(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-09-14T09:00:00+03:00');

        self::assertNotContains(self::MONDAY, $dates);
    }

    // ------------------------------------------------------------- weekdays

    public function test_a_plan_offers_its_weekdays_in_ascending_order(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-09-14T07:00:00+03:00');

        self::assertSame([
            '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18',
            '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25',
            '2026-09-28',
        ], $dates);
    }

    public function test_saturdays_and_sundays_are_never_offered(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-09-14T07:00:00+03:00');

        foreach ($dates as $date) {
            $iso = $this->day($date)->dayOfWeekIso;
            self::assertLessThanOrEqual(5, $iso, "$date is not a weekday");
        }
    }

    public function test_friday_looks_past_the_weekend_to_monday(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-09-18T09:00:00+03:00');

        // Friday itself has departed; Saturday and Sunday are not service days.
        self::assertSame('2026-09-21', $dates[0]);
    }

    public function test_a_weekend_offers_nothing_until_monday(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-09-19T12:00:00+03:00');

        self::assertSame('2026-09-21', $dates[0]);
        self::assertNotContains('2026-09-19', $dates);
        self::assertNotContains('2026-09-20', $dates);
    }

    /**
     * A public holiday is still a weekday to this product.
     *
     * 29 October is a Thursday in 2026 and a national holiday in the pilot's
     * country. There is no holiday calendar, and adding one here would mean
     * deciding on a driver's behalf that they are not driving.
     */
    public function test_a_weekday_public_holiday_is_still_offered(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-10-20T07:00:00+03:00');

        self::assertContains('2026-10-29', $dates);
    }

    // -------------------------------------------------------------- horizon

    public function test_the_fourteenth_day_ahead_is_offered(): void
    {
        // A Monday fourteen days after a Monday.
        $dates = $this->datesOf($this->plan(), '2026-09-14T07:00:00+03:00');

        self::assertContains('2026-09-28', $dates);
    }

    public function test_the_fifteenth_day_ahead_is_not_offered(): void
    {
        // 2026-09-29 is a Tuesday, so nothing but the horizon keeps it out.
        $dates = $this->datesOf($this->plan(), '2026-09-14T07:00:00+03:00');

        self::assertNotContains('2026-09-29', $dates);
    }

    public function test_no_offered_day_is_more_than_fourteen_days_ahead(): void
    {
        $dates = $this->datesOf($this->plan(), '2026-09-14T07:00:00+03:00');

        self::assertNotSame([], $dates);

        foreach ($dates as $date) {
            self::assertLessThanOrEqual(
                14,
                (int) $this->day(self::MONDAY)->diffInDays($this->day($date), absolute: false),
                "$date is beyond the horizon",
            );
        }
    }

    // ------------------------------------------------------------ timezones

    /**
     * One instant, two zones, two different "todays".
     *
     * At 21:00 UTC it is already Tuesday in Istanbul and still Monday in New
     * York, and these plans depart at 23:00 — so Istanbul's first offered day is
     * Tuesday while New York's is the Monday that has not yet left. The far end
     * moves with it: fourteen days from a different today is a different date.
     *
     * A client reading its own device clock would answer one of these for both.
     */
    public function test_the_route_zone_decides_today_not_the_server(): void
    {
        $instant = '2026-09-14T21:00:00+00:00';

        $istanbul = $this->datesOf($this->plan('23:00', 'Europe/Istanbul'), $instant);
        $newYork = $this->datesOf($this->plan('23:00', 'America/New_York'), $instant);

        self::assertSame('2026-09-15', $istanbul[0]);
        self::assertSame('2026-09-29', $istanbul[array_key_last($istanbul)]);

        self::assertSame('2026-09-14', $newYork[0]);
        self::assertSame('2026-09-28', $newYork[array_key_last($newYork)]);
    }

    /**
     * The days are still calendar days across a daylight-saving change.
     *
     * Europe/Berlin springs forward on Sunday 29 March 2026, so the fortnight
     * from Monday the 23rd is an hour shorter than fourteen times twenty-four.
     * Every date here is therefore walked over that boundary, and each must
     * still be the day it says it is: one hour of slippage anywhere in the walk
     * renames every date after it, and a member would be offered a Sunday.
     *
     * WHAT THIS CASE DOES NOT PROVE
     *
     * Not the horizon. The walk asks for fifteen candidates and no more, so the
     * far edge here is enforced by the loop's own bound — pinned by the horizon
     * constant, above — rather than by `SeatRequestHorizon` rejecting anything.
     * That the horizon itself survives a daylight-saving change is its own
     * question, and it is asked in `SeatRequestHorizonTest`.
     */
    public function test_the_days_stay_calendar_days_across_a_daylight_saving_change(): void
    {
        $dates = $this->datesOf(
            $this->plan('08:00', 'Europe/Berlin'),
            '2026-03-23T07:00:00+01:00',
        );

        self::assertSame([
            '2026-03-23', '2026-03-24', '2026-03-25', '2026-03-26', '2026-03-27',
            '2026-03-30', '2026-03-31', '2026-04-01', '2026-04-02', '2026-04-03',
            '2026-04-06',
        ], $dates);

        // The weekend that carries the change is absent as a weekend, not as
        // an accident of arithmetic.
        self::assertNotContains('2026-03-28', $dates);
        self::assertNotContains('2026-03-29', $dates);
    }

    // -------------------------------------------------------------- one-off

    public function test_a_one_off_offers_its_own_day(): void
    {
        $route = $this->route(Recurrence::Once, self::MONDAY);

        self::assertSame(
            [self::MONDAY],
            $this->datesOf($route, '2026-09-14T07:00:00+03:00'),
        );
    }

    public function test_a_departed_one_off_offers_nothing(): void
    {
        $route = $this->route(Recurrence::Once, self::MONDAY);

        self::assertSame([], $this->datesOf($route, '2026-09-14T09:00:00+03:00'));
    }

    /**
     * The fourteen-day horizon is a recurring rule and must not reach one-off
     * routes. A journey published for December is requestable in September,
     * which is Phase 13 behaviour Phase 16b did not narrow.
     */
    public function test_a_distant_one_off_is_still_offered(): void
    {
        $route = $this->route(Recurrence::Once, '2026-12-01');

        self::assertSame(
            ['2026-12-01'],
            $this->datesOf($route, '2026-09-14T07:00:00+03:00'),
        );
    }

    /**
     * A one-off route runs on the day it was published for, whatever day that
     * is. The weekday rule belongs to plans.
     */
    public function test_a_one_off_on_a_saturday_is_still_offered(): void
    {
        $route = $this->route(Recurrence::Once, '2026-09-19');

        self::assertSame(
            ['2026-09-19'],
            $this->datesOf($route, '2026-09-14T07:00:00+03:00'),
        );
    }

    // ------------------------------------------------------- the equivalence

    /**
     * Every day offered is a day the request path accepts, and every day inside
     * the window that is not offered is one it refuses.
     *
     * The reason the class exists, asserted directly rather than trusted: the
     * list is generated by asking `RequestableJourney`, and this walks the same
     * fifteen candidates independently to prove the generation did not filter,
     * reorder or extend anything on its own.
     */
    public function test_the_offered_days_are_exactly_the_days_the_writer_admits(): void
    {
        $route = $this->plan();
        $now = $this->at('2026-09-14T09:00:00+03:00');
        $offered = $this->datesOf($route, '2026-09-14T09:00:00+03:00');

        $expected = [];
        for ($ahead = 0; $ahead <= 14; $ahead++) {
            $day = $this->day(self::MONDAY)->addDays($ahead);

            if (RequestableJourney::admits($route, $day, $now)) {
                $expected[] = $day->format('Y-m-d');
            }
        }

        self::assertSame($expected, $offered);
    }

    /**
     * Nobody's askings can reach this answer, because nobody is passed in.
     *
     * `requestable_service_dates` is what the ROUTE offers; which of those days
     * a particular member has already spent is `my_seat_requests`, and the two
     * are published separately so a client can tell a departed day from a day it
     * has used. A future edit that took an `Account` here to "helpfully" remove
     * the caller's own dates would merge two answers into one that means
     * neither — and would fail here first.
     */
    public function test_the_answer_cannot_depend_on_who_is_asking(): void
    {
        $parameters = array_map(
            static function (ReflectionParameter $parameter): string {
                $type = $parameter->getType();

                return $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
            },
            (new \ReflectionMethod(RequestableServiceDates::class, 'of'))->getParameters(),
        );

        self::assertSame([Route::class, CarbonImmutable::class], $parameters);
    }
}
