<?php

declare(strict_types=1);

namespace App\Journeys;

use App\Models\Account;
use App\Models\Route;
use App\Models\Trip;
use App\Routes\Recurrence;
use App\Routes\RouteStatus;
use App\Trips\TripStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dated journeys a driver can act on, newest service date first.
 *
 * TWO INCLUSION BRANCHES, AND NOTHING ELSE
 *
 *   A. **Today's journey.** The caller owns the route, the route is published,
 *      and the route runs on today — today read in THAT ROUTE'S timezone. It is
 *      included whatever the trip says, including when there is no trip at all,
 *      because a journey a driver may still start is the whole point of the
 *      feed.
 *
 *   B. **An unfinished journey.** A trip of the caller's is `in_progress`, on
 *      any service date, whatever the route's status now is. Without this a
 *      journey that crossed midnight would vanish from the driver's own hands
 *      at the moment they still need to end it, and so would one whose plan was
 *      cancelled while it was under way.
 *
 * A journey in both branches is ONE journey. The candidate rows are grouped by
 * `(route_id, service_date)` in the database, so today's started journey cannot
 * arrive twice however many sources found it.
 *
 * WHAT IT IS NOT
 *
 * Not history. A journey that was completed, aborted or simply never started
 * yesterday is absent, and no parameter widens it — `GET
 * /api/v1/routes/{routeId}/journeys/{serviceDate}` is the addressed read for
 * any day the caller can name. Not "upcoming" either: tomorrow is not here.
 *
 * TODAY IS THE ROUTE'S, NOT THE SERVER'S
 *
 * `(:now at time zone routes.timezone)::date` — PostgreSQL converts the instant
 * into each route's own zone and takes the calendar day there. A server-local
 * date would move every driver's today by however far the deployment happens to
 * be from them, and would be invisible in a pilot where every route shares one
 * zone.
 *
 * WHICH DAYS A PLAN RUNS ON IS STILL `RouteDeparture`'S
 *
 * The SQL below narrows to candidates and never decides. It admits every
 * weekday plan and then `RouteDeparture::runsOn` drops the ones that do not run
 * on that day, so the rule has one implementation. That is why the page is
 * filled by scanning: a plain `LIMIT n` would return a page short by however
 * many candidates the domain rejected.
 *
 * A one-off route is narrowed to its own date in the query as well, which for
 * that recurrence happens to be the same predicate. It is a PRE-FILTER, not an
 * answer — like the date slack in `DiscoverRoutes` — and it exists so a driver's
 * years of past one-off journeys are not scanned every time. Removing it would
 * cost a wider scan and change no result, because the domain is asked either
 * way; a test pins that.
 *
 * THE INVARIANTS THAT MAKE THAT SAFE — the same ones `App\Routes\DiscoverRoutes`
 * relies on:
 *
 *   * ordering is `(service_date desc, route_id desc)` throughout, so each
 *     window resumes exactly where the last one stopped;
 *   * the cursor names the last journey actually RETURNED, never the last
 *     candidate scanned;
 *   * finding one eligible journey BEYOND the page is what proves more exist,
 *     so a null cursor means exhausted;
 *   * every iteration consumes at least one candidate row and the candidate set
 *     is finite, so the loop terminates. No iteration cap, because a cap is an
 *     arbitrary horizon that silently truncates somebody's results.
 */
final class ListMyJourneys
{
    public function __invoke(
        Account $driver,
        ?JourneyCursor $cursor = null,
        int $limit = 20,
        ?CarbonImmutable $now = null,
    ): JourneyPage {
        $now ??= CarbonImmutable::now();

        // One more than the caller asked for. Finding it is what distinguishes
        // "there is another page" from "that was everything".
        $wanted = $limit + 1;

        /** @var list<JourneyCursor> $eligible */
        $eligible = [];
        $scan = $cursor;

        while (count($eligible) < $wanted) {
            $candidates = $this->candidates($driver, $now, $scan)->limit($wanted)->get();

            if ($candidates->isEmpty()) {
                break;
            }

            $examined = $candidates->map(
                static fn (object $row): JourneyCandidate => JourneyCandidate::fromRow($row),
            );

            foreach ($examined as $candidate) {
                if ($this->runs($candidate)) {
                    $eligible[] = $candidate->position();
                }
            }

            // Advance past everything just examined, eligible or not. This is
            // the SCAN position and is never handed to the caller. The window
            // is non-empty by the check above, so a last row always exists.
            $last = $examined->last();

            if (! $last instanceof JourneyCandidate) {
                break;
            }

            $scan = $last->position();

            // A short window means the candidate set is exhausted.
            if ($candidates->count() < $wanted) {
                break;
            }
        }

        $hasMore = count($eligible) > $limit;
        $page = array_slice($eligible, 0, $limit);

        return new JourneyPage(
            $this->hydrate($page),
            $hasMore && $page !== [] ? $page[count($page) - 1] : null,
        );
    }

    /**
     * The candidate journeys, deduplicated and in order.
     *
     * `union all` then `group by` rather than `union`: the two branches carry
     * different flags, and the grouping is what folds a journey found by both
     * into one row while remembering that it WAS found by both. A plain `union`
     * would dedupe only rows that matched in every column, so today's started
     * journey would arrive twice.
     */
    private function candidates(Account $driver, CarbonImmutable $now, ?JourneyCursor $from): Builder
    {
        // The instant is bound with its offset rather than handed over as a
        // naive local string: the driver's today must not depend on what the
        // database session happens to think the zone is.
        $instant = $now->utc()->format('Y-m-d H:i:s.uP');
        $today = '(?::timestamptz at time zone routes.timezone)::date';

        // A. Today's journey of a published route. Every weekday plan is a
        //    candidate and `runs()` decides; a one-off route is a candidate only
        //    on its own date, which is the column rather than a rule.
        $todays = DB::table('routes')
            ->selectRaw(
                'routes.id as route_id, '
                .$today.' as service_date, '
                .'routes.recurrence, routes.departure_date, routes.departure_time, '
                .'routes.timezone, true as is_today, false as is_running',
                [$instant],
            )
            ->where('routes.account_id', $driver->id)
            ->where('routes.status', RouteStatus::Published->value)
            ->where(function (Builder $plan) use ($today, $instant): void {
                $plan
                    ->where('routes.recurrence', Recurrence::Weekdays->value)
                    ->orWhereRaw('routes.departure_date = '.$today, [$instant]);
            });

        // B. An owned journey still under way, on any date, whatever the route
        //    has since become. Nothing here asks about the plan.
        $running = DB::table('trips')
            ->join('routes', 'routes.id', '=', 'trips.route_id')
            ->selectRaw(
                'trips.route_id as route_id, trips.service_date as service_date, '
                .'routes.recurrence, routes.departure_date, routes.departure_time, '
                .'routes.timezone, false as is_today, true as is_running',
            )
            ->where('routes.account_id', $driver->id)
            ->where('trips.status', TripStatus::InProgress->value);

        $query = DB::query()
            ->fromSub($todays->unionAll($running), 'candidates')
            ->select([
                'route_id',
                'service_date',
                'recurrence',
                'departure_date',
                'departure_time',
                'timezone',
            ])
            ->selectRaw('bool_or(is_today) as is_today, bool_or(is_running) as is_running')
            ->groupBy(
                'route_id',
                'service_date',
                'recurrence',
                'departure_date',
                'departure_time',
                'timezone',
            )
            ->orderByDesc('service_date')
            ->orderByDesc('route_id');

        if ($from instanceof JourneyCursor) {
            // The tuple comparison PostgreSQL understands directly, which is
            // exactly the ordering above.
            $query->whereRaw(
                '(service_date, route_id) < (?::date, ?)',
                [$from->serviceDate->format('Y-m-d'), $from->routeId],
            );
        }

        return $query;
    }

    /**
     * Does this candidate belong in the feed?
     *
     * A journey under way is in, unconditionally: branch B asks nothing about
     * the plan, because a trip that exists happened whatever the plan says now.
     * A today-only candidate is in when the route actually runs that day, and
     * that is `RouteDeparture`'s answer rather than a second weekday rule
     * written in SQL.
     */
    private function runs(JourneyCandidate $candidate): bool
    {
        if ($candidate->isRunning) {
            return true;
        }

        return $candidate->departure->runsOn($candidate->serviceDate);
    }

    /**
     * The page's journeys, in two queries however long the page is.
     *
     * The candidate scan deals in ids and dates; nothing about a route or a trip
     * is read until the page is settled, and then everything is read at once.
     * Fetching either per row would be the N+1 the scan was careful not to be.
     *
     * @param  list<JourneyCursor>  $page
     * @return list<Journey>
     */
    private function hydrate(array $page): array
    {
        if ($page === []) {
            return [];
        }

        $routeIds = array_values(array_unique(array_map(
            static fn (JourneyCursor $at): string => $at->routeId,
            $page,
        )));

        $routes = Route::query()
            ->with(['originPlace', 'destinationPlace'])
            ->whereIn('id', $routeIds)
            ->get()
            ->keyBy('id');

        // Keyed by the journey, never by the route: a recurring plan may hold
        // several trips, and taking a route's trip without its date is the
        // choice `(route_id, service_date)` exists to make impossible.
        $trips = [];
        foreach (Trip::query()->whereIn('route_id', $routeIds)->get() as $trip) {
            $trips[$trip->route_id.'@'.$trip->service_date->toDateString()] = $trip;
        }

        $journeys = [];
        foreach ($page as $at) {
            $route = $routes->get($at->routeId);

            // A candidate whose route vanished between the scan and the read.
            // Dropped rather than rendered: there is nothing truthful to say
            // about a journey whose plan is gone.
            if (! $route instanceof Route) {
                continue;
            }

            $journeys[] = new Journey(
                $route,
                $at->serviceDate,
                $trips[$at->routeId.'@'.$at->serviceDate->toDateString()] ?? null,
            );
        }

        return $journeys;
    }
}
