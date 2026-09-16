<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Reviews\ReviewWindow;
use App\Routes\Recurrence;
use App\Routes\RouteDeparture;
use App\SeatRequests\SeatRequestHorizon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * How far ahead a recurring plan may be asked about.
 *
 * The boundary is the whole subject: today is in, today + fourteen is in, and
 * the day after is not. Each edge gets its own case, because an off-by-one here
 * is the kind of thing that passes every test written about the middle.
 */
final class SeatRequestHorizonTest extends TestCase
{
    private const TODAY = '2026-09-14T09:00:00+03:00';

    private function plan(string $timezone = 'Europe/Istanbul'): RouteDeparture
    {
        return RouteDeparture::fromInput(Recurrence::Weekdays, null, '08:00', $timezone);
    }

    private function day(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        self::assertInstanceOf(CarbonImmutable::class, $parsed);

        return $parsed;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY);
    }

    // ----------------------------------------------------------- the boundary

    public function test_today_is_inside_the_horizon(): void
    {
        self::assertTrue(
            SeatRequestHorizon::admits($this->plan(), $this->day('2026-09-14'), $this->now()),
        );
    }

    /** CARRIES WEIGHT. The last day is inclusive. */
    public function test_the_fourteenth_day_ahead_is_inside_the_horizon(): void
    {
        self::assertTrue(
            SeatRequestHorizon::admits($this->plan(), $this->day('2026-09-28'), $this->now()),
        );
    }

    /** CARRIES WEIGHT. And the fifteenth is not. */
    public function test_the_fifteenth_day_ahead_is_outside_the_horizon(): void
    {
        self::assertFalse(
            SeatRequestHorizon::admits($this->plan(), $this->day('2026-09-29'), $this->now()),
        );
    }

    public function test_yesterday_is_outside_the_horizon(): void
    {
        self::assertFalse(
            SeatRequestHorizon::admits($this->plan(), $this->day('2026-09-13'), $this->now()),
        );
    }

    // ------------------------------------------------------------- whose today

    /**
     * CARRIES WEIGHT. The window is measured where the route is read.
     *
     * At 22:30 UTC it is already the 15th in İstanbul, so the 29th is the
     * fourteenth day ahead and still open — while a server-day measurement
     * would have closed it.
     */
    public function test_the_window_is_measured_in_the_routes_timezone(): void
    {
        $lateUtc = CarbonImmutable::parse('2026-09-14T22:30:00Z');

        self::assertTrue(
            SeatRequestHorizon::admits($this->plan(), $this->day('2026-09-29'), $lateUtc),
        );
        self::assertFalse(
            SeatRequestHorizon::admits($this->plan(), $this->day('2026-09-14'), $lateUtc),
            'the 14th is yesterday in İstanbul by then',
        );
    }

    /**
     * CARRIES WEIGHT. The window is fourteen DAYS, not three hundred and
     * thirty-six hours.
     *
     * Europe/Berlin springs forward on Sunday 29 March 2026, so the fortnight
     * beginning Monday the 23rd is an hour short of fourteen twenty-four-hour
     * days. Counted as elapsed time the fifteenth day would measure just inside
     * the boundary and be admitted — a member offered a day the create path
     * then refuses, once a year, in a zone the pilot does not run in.
     *
     * Istanbul cannot catch this. Türkiye has not observed daylight saving
     * since 2016, so a fixed +03 and a real zone agree there on every date of
     * every year, which is exactly why this case is written somewhere else.
     */
    public function test_a_daylight_saving_change_does_not_move_the_boundary(): void
    {
        $berlin = $this->plan('Europe/Berlin');
        $now = CarbonImmutable::parse('2026-03-23T09:00:00+01:00');

        self::assertTrue(
            SeatRequestHorizon::admits($berlin, $this->day('2026-04-06'), $now),
            'the fourteenth day ahead was closed by the hour the clocks moved',
        );
        self::assertFalse(
            SeatRequestHorizon::admits($berlin, $this->day('2026-04-07'), $now),
            'the fifteenth day ahead was admitted by counting elapsed time',
        );
    }

    // --------------------------------------------------------- what it is not

    /**
     * CARRIES WEIGHT. A one-off journey is not bounded by it.
     *
     * A journey published for a date two months out is requestable today, and
     * Phase 16b does not narrow Phase 13's behaviour. The rule lives in the
     * primitive so no caller can forget it.
     */
    public function test_a_one_off_route_is_not_bounded_by_the_horizon(): void
    {
        $distant = RouteDeparture::fromInput(
            Recurrence::Once,
            '2026-12-24',
            '08:00',
            'Europe/Istanbul',
        );

        self::assertTrue(
            SeatRequestHorizon::admits($distant, $this->day('2026-12-24'), $this->now()),
        );
    }

    /**
     * The same number as the review window, and deliberately not the same
     * constant.
     *
     * They answer different questions, so lengthening the period a rating may
     * be written in must never move everybody's booking horizon with it.
     */
    public function test_the_horizon_is_independent_of_the_review_window(): void
    {
        self::assertSame(14, SeatRequestHorizon::MAX_DAYS_AHEAD);
        self::assertSame(14, ReviewWindow::DAYS);

        $horizon = file_get_contents(app_path('SeatRequests/SeatRequestHorizon.php'));
        self::assertIsString($horizon);

        $code = preg_replace('#^\s*(\*|//|/\*).*$#m', '', $horizon);
        self::assertIsString($code);
        self::assertStringNotContainsString('ReviewWindow::', $code);
    }
}
