<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Route;
use App\Routes\Recurrence;
use App\Routes\RouteStatus;
use App\SeatRequests\RequestableJourney;
use App\SeatRequests\ServiceDateRefused;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Which dated journey of a route may be asked about, and why not.
 *
 * TWO SHAPES OF ONE DECISION, PINNED TOGETHER
 *
 * `resolve` is what the create path asks and `admits` is what discovery asks,
 * and the point of the class is that they cannot disagree. So most cases here
 * assert BOTH: a day resolve accepts is a day admits shows, and a day resolve
 * refuses is a day admits hides. A future edit that answered one without the
 * other would be exactly the drift this class exists to prevent, and it fails
 * here rather than as a card offering a journey a `422` then rejects.
 *
 * The route is built in memory rather than saved. Nothing under test reads the
 * database — recurrence, date, time and zone are all columns already in hand —
 * and a fixture that inserted rows would be testing the schema instead.
 */
final class RequestableJourneyTest extends TestCase
{
    private const NOW = '2026-09-14T09:00:00+03:00';

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }

    private function day(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        self::assertInstanceOf(CarbonImmutable::class, $parsed);

        return $parsed;
    }

    private function route(Recurrence $recurrence, ?string $date, string $time = '08:00'): Route
    {
        $route = new Route;
        $route->id = '01991c00-0000-7000-8000-000000000001';
        $route->recurrence = $recurrence;
        $route->departure_date = $date === null ? null : $this->day($date);
        $route->departure_time = $time.':00';
        $route->timezone = 'Europe/Istanbul';
        $route->status = RouteStatus::Published;

        return $route;
    }

    private function plan(): Route
    {
        return $this->route(Recurrence::Weekdays, null);
    }

    /**
     * Assert both shapes agree that this day is open.
     */
    private function assertOffers(Route $route, string $date): void
    {
        $day = $this->day($date);

        self::assertSame(
            $date,
            RequestableJourney::resolve($route, $day, $this->now())->format('Y-m-d'),
        );
        self::assertTrue(RequestableJourney::admits($route, $day, $this->now()));
    }

    /**
     * Assert both shapes agree that this day is not, and say why.
     */
    private function assertRefuses(Route $route, string $date, string $because): void
    {
        $day = $this->day($date);

        try {
            RequestableJourney::resolve($route, $day, $this->now());
            self::fail("resolve accepted $date, which it must refuse: $because");
        } catch (ServiceDateRefused $refusal) {
            self::assertStringContainsString($because, $refusal->getMessage());
        }

        self::assertFalse(
            RequestableJourney::admits($route, $day, $this->now()),
            "admits showed $date, which resolve refuses: $because",
        );
    }

    // ------------------------------------------------------------ a plan

    public function test_a_weekday_inside_the_horizon_is_open(): void
    {
        // A Tuesday, four days out.
        $this->assertOffers($this->plan(), '2026-09-15');
    }

    /** CARRIES WEIGHT. Today's journey is open until it leaves. */
    public function test_todays_journey_is_open_until_its_departure(): void
    {
        // 08:00 departure, 09:00 now: today has already gone.
        $this->assertRefuses($this->plan(), '2026-09-14', 'already departed');

        // The same plan an hour earlier would still have been open.
        $earlier = CarbonImmutable::parse('2026-09-14T07:00:00+03:00');
        self::assertTrue(
            RequestableJourney::admits($this->plan(), $this->day('2026-09-14'), $earlier),
        );
    }

    /** CARRIES WEIGHT. Yesterday is gone, and stays gone. */
    public function test_a_past_day_is_closed(): void
    {
        $this->assertRefuses($this->plan(), '2026-09-11', 'already departed');
    }

    /** CARRIES WEIGHT. A Saturday is never a journey on a weekday plan. */
    public function test_a_day_the_route_does_not_run_is_closed(): void
    {
        $this->assertRefuses($this->plan(), '2026-09-19', 'does not run');
    }

    /** CARRIES WEIGHT. The horizon's far edge is inclusive, and one past it is not. */
    public function test_the_horizon_bounds_which_days_are_open(): void
    {
        // 2026-09-28 is a Monday, fourteen days out.
        $this->assertOffers($this->plan(), '2026-09-28');
        // 2026-09-29, a Tuesday, is fifteen.
        $this->assertRefuses($this->plan(), '2026-09-29', 'further out');
    }

    /** A plan cannot be asked about without naming a day. */
    public function test_a_plan_refuses_an_unnamed_day(): void
    {
        $this->expectException(ServiceDateRefused::class);

        RequestableJourney::resolve($this->plan(), null, $this->now());
    }

    // -------------------------------------------------------- a one-off

    public function test_a_one_off_route_needs_no_day_named(): void
    {
        $route = $this->route(Recurrence::Once, '2026-09-18');

        self::assertSame(
            '2026-09-18',
            RequestableJourney::resolve($route, null, $this->now())->format('Y-m-d'),
        );
    }

    public function test_a_one_off_route_accepts_its_own_day(): void
    {
        $this->assertOffers($this->route(Recurrence::Once, '2026-09-18'), '2026-09-18');
    }

    /** CARRIES WEIGHT. A one-off journey's day is not the caller's to choose. */
    public function test_a_one_off_route_refuses_any_other_day(): void
    {
        $this->assertRefuses(
            $this->route(Recurrence::Once, '2026-09-18'),
            '2026-09-17',
            'runs on a single day',
        );
    }

    /**
     * CARRIES WEIGHT. `admits` is stricter than `resolve` here, deliberately.
     *
     * A departed one-off journey is still that route's only day, so the date
     * rule has nothing to object to and `resolve` returns it — the create path
     * has already answered `404` well before this point, because a journey
     * nothing can see must not be confirmed to exist. A reader asking whether
     * the day is open gets the honest answer instead of that disclosure rule.
     */
    public function test_a_departed_one_off_day_is_closed_to_a_reader(): void
    {
        $route = $this->route(Recurrence::Once, '2026-09-14');

        self::assertSame(
            '2026-09-14',
            RequestableJourney::resolve($route, null, $this->now())->format('Y-m-d'),
        );
        self::assertFalse(
            RequestableJourney::admits($route, $this->day('2026-09-14'), $this->now()),
        );
    }

    /** The horizon does not bound a one-off journey, however far out it is. */
    public function test_a_distant_one_off_journey_is_still_open(): void
    {
        $this->assertOffers($this->route(Recurrence::Once, '2026-12-24'), '2026-12-24');
    }
}
