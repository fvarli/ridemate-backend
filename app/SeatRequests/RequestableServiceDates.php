<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Route;
use App\Routes\Recurrence;
use Carbon\CarbonImmutable;

/**
 * Every dated journey of a route that may be asked about right now, listed.
 *
 * WHY A LIST EXISTS AT ALL
 *
 * `RequestableJourney` answers the question one day at a time, which is all a
 * writer ever needs: the caller names a day and the day is accepted or refused.
 * A reader has the opposite problem. A client that must offer a member their
 * choice of days cannot name one to ask about — it does not know which days
 * there are, and working them out means knowing what "today" is in the route's
 * timezone, when that day's departure passes, and how far the horizon reaches.
 * That is route-local temporal truth, and Phase 16b established it is the
 * server's: a client computing it would need an IANA database of its own, kept
 * current by app releases, implementing a second copy of rules that live here.
 *
 * So this enumerates. It is the same decision as `RequestableJourney::admits`,
 * asked about every candidate day instead of one named day.
 *
 * NOTHING HERE DECIDES ANYTHING
 *
 * There is no weekday rule in this file, no horizon arithmetic, no departure
 * comparison and no timezone conversion. Every candidate is handed to
 * `RequestableJourney::admits`, which is what the create path asks, so a day
 * this returns is a day that path accepts on its temporal rules at that same
 * instant. That equivalence is the entire value of the class, and it holds
 * because the rule is borrowed rather than repeated — the moment somebody
 * writes `dayOfWeekIso` here, discovery and the writer can disagree.
 *
 * WHAT IT DOES NOT MEAN
 *
 * Temporal eligibility, and nothing else. A day listed here may still be
 * refused a seat request for reasons that have no date in them: the route may
 * be full, the caller may own it, the caller may have asked already. In
 * particular this is NOT filtered by who is asking — see below.
 *
 * DELIBERATELY NOT PER-CALLER
 *
 * A member who already asked about Tuesday, and was declined, still sees
 * Tuesday here. That is not an oversight and must not be "fixed": these are two
 * separate truths, and discovery publishes both.
 *
 *   this class          which days the ROUTE can be asked about
 *   `MySeatRequestLookup`   which of those days THIS CALLER has already spent
 *
 * A client subtracts the second from the first to get the days it may offer.
 * Doing that subtraction here would collapse the two into one number that
 * answers neither question well: a day would vanish from the route's own
 * availability because of something personal to one viewer, and the next reader
 * could not tell an absent day that has departed from an absent day the caller
 * asked about last week.
 */
final class RequestableServiceDates
{
    private function __construct() {}

    /**
     * The route's currently askable days, earliest first.
     *
     * Empty is an ordinary answer: a weekday plan over a weekend whose Monday
     * has not yet arrived has none, and neither has a one-off journey that has
     * left.
     *
     * @return list<CarbonImmutable> bare calendar dates, ascending
     */
    public static function of(Route $route, ?CarbonImmutable $now = null): array
    {
        if ($route->recurrence === Recurrence::Once) {
            return self::soleDay($route, $now);
        }

        return self::plannedDays($route, $now);
    }

    /**
     * A one-off route's single day, if it is still ahead.
     *
     * THE HORIZON IS NOT APPLIED, AND THIS FILE IS NOT WHY
     *
     * A journey published for a date two months out is requestable today, which
     * is Phase 13 behaviour that Phase 16b did not narrow. There is no exemption
     * written here: `RequestableJourney` answers a one-off route from its own
     * date and returns before the horizon is consulted at all, so asking
     * `admits` is what keeps a route published for next March listed. The rule
     * has one home, and it is not this one.
     *
     * @return list<CarbonImmutable>
     */
    private static function soleDay(Route $route, ?CarbonImmutable $now): array
    {
        $only = $route->soleServiceDate();

        return RequestableJourney::admits($route, $only, $now) ? [$only] : [];
    }

    /**
     * A recurring plan's days, from the route's today to the horizon's edge.
     *
     * FIFTEEN CANDIDATES, OFFERED ONE AT A TIME
     *
     * Today counts as day zero, so today through today + `MAX_DAYS_AHEAD` are
     * examined: at most fifteen calendar dates, of which the weekday rule keeps
     * around eleven. The count comes from `SeatRequestHorizon` rather than being
     * written here, so moving the horizon moves this with it.
     *
     * EACH DAY IS MEASURED FROM TODAY, NOT FROM THE DAY BEFORE
     *
     * `$today->addDays($ahead)` rather than stepping a cursor forward. Both walk
     * midnights correctly across a daylight-saving change, but only the first
     * cannot accumulate: a single wrong step in a loop that carries its own
     * position shifts every day after it, and the mistake would show up as one
     * missing Friday three weeks into a zone the pilot does not run in.
     *
     * @return list<CarbonImmutable>
     */
    private static function plannedDays(Route $route, ?CarbonImmutable $now): array
    {
        // The route's own calendar day, read in the route's own zone. The only
        // thing this method takes from the clock, and it takes it through the
        // departure, which owns the timezone it is read in.
        $today = $route->departure()->localDate($now);

        $days = [];

        for ($ahead = 0; $ahead <= SeatRequestHorizon::MAX_DAYS_AHEAD; $ahead++) {
            $day = $today->addDays($ahead);

            // Weekends, the day that has already departed, and anything the
            // horizon refuses are all rejected in here rather than out here.
            if (RequestableJourney::admits($route, $day, $now)) {
                $days[] = $day;
            }
        }

        return $days;
    }
}
