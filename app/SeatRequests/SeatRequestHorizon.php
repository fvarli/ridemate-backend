<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Routes\Recurrence;
use App\Routes\RouteDeparture;
use Carbon\CarbonImmutable;

/**
 * How far ahead a recurring journey may be asked about.
 *
 * A weekday plan runs *until cancelled*, so without a bound the question "which
 * days may I ask for" has no answer, and a member could hold a seat on a
 * morning three years away. ADR 0008 deferred `route_occurrences` precisely
 * because no such number could be derived from anything then; Phase 16b is the
 * consumer that finally decides one, which is what that decision said would
 * happen.
 *
 * FOURTEEN DAYS, AND DELIBERATELY NOT THE REVIEW WINDOW'S FOURTEEN
 *
 * `App\Reviews\ReviewWindow::DAYS` is also 14 and this must never reference it.
 * They answer different questions — how long a rating may be written, and how
 * far ahead a seat may be asked for — and tying them together would mean
 * lengthening the review period silently moved everybody's booking horizon too.
 * The same number twice is a coincidence worth keeping separate.
 *
 * THE HORIZON BOUNDS ASKING, NOT THE PLAN
 *
 * A plan does not end in fourteen days. Nothing here may be rendered as an end
 * date, a validity period, or a schedule running out.
 *
 * ONE-OFF ROUTES ARE NOT BOUNDED BY IT
 *
 * A journey published for a date two months out is requestable today and stays
 * requestable — that is Phase 13's behaviour and Phase 16b does not narrow it.
 * The rule lives here rather than at the call site so it cannot be forgotten by
 * one caller and remembered by another.
 */
final class SeatRequestHorizon
{
    /**
     * The last day ahead of today that may be asked for, inclusive.
     *
     * Today counts as day zero, so today through today + 14 are open: fifteen
     * calendar dates at most, and never "a fourteen-date window".
     */
    public const MAX_DAYS_AHEAD = 14;

    private function __construct() {}

    /**
     * May this route be asked about for that day, as far as the horizon cares?
     *
     * Says nothing about whether the route runs that day, whether it is still
     * published, or whether the departure has passed. Those are separate
     * questions with their own answers, and folding them together here is how a
     * refusal ends up naming the wrong reason.
     *
     * Today is read in the ROUTE's timezone, never the server's: a horizon
     * measured where the process happens to run would move a member's last
     * selectable day by however far the deployment is from the pilot.
     */
    public static function admits(
        RouteDeparture $departure,
        CarbonImmutable $serviceDate,
        ?CarbonImmutable $now = null,
    ): bool {
        if ($departure->recurrence === Recurrence::Once) {
            return true;
        }

        // The distance itself belongs to the route, which owns the timezone it
        // is measured in. This class owns only where the line falls.
        $ahead = $departure->daysUntil($serviceDate, $now);

        return $ahead >= 0 && $ahead <= self::MAX_DAYS_AHEAD;
    }
}
