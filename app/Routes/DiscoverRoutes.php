<?php

declare(strict_types=1);

namespace App\Routes;

use App\Models\Account;
use App\Models\Profile;
use App\Models\Route;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Journeys somebody else has published between two places.
 *
 * MATCHING IS EXACT, AND DELIBERATELY NOT SPATIAL
 *
 * Origin must be the requested origin and destination the requested
 * destination, in that direction. No radius, no corridor, no distance: a
 * straight line between two points is not a driving corridor, and the product
 * has no routing data to build a real one from. `places.point` stays unread and
 * unindexed until a phase can use it truthfully. The reasoning is recorded in
 * the Phase 12 decision record rather than here.
 *
 * ONE IMPLEMENTATION OF THE CLOCK
 *
 * Whether a departure is still ahead of a member is RouteDeparture::state's
 * answer, read in the route's own timezone. It is not restated in SQL, because
 * two implementations of that rule would eventually disagree about a route on
 * the day it departs.
 *
 * That is why paging works the way it does below. The database chooses
 * candidates and the domain decides which are eligible, so a plain `LIMIT n`
 * would return a page short by however many candidates the domain rejected —
 * sometimes an empty one with perfectly good routes sitting just behind it. The
 * scan advances through candidate windows instead, until it has enough eligible
 * routes or the candidate set runs out.
 *
 * THE INVARIANTS THAT MAKE THAT SAFE
 *
 *   * ordering is `(created_at desc, id desc)` throughout, so each window
 *     resumes exactly where the last one stopped;
 *   * the cursor names the last route actually RETURNED, never the last
 *     candidate scanned — rejected candidates are stepped over once and never
 *     revisited, and no eligible route is stepped over at all;
 *   * finding one eligible route BEYOND the page is what proves more exist, so
 *     a null cursor means genuinely exhausted rather than "this window ended";
 *   * the loop terminates because every iteration consumes at least one
 *     candidate row and the candidate set is finite. There is no iteration cap,
 *     because a cap would be an arbitrary horizon that silently truncated
 *     somebody's results.
 */
final class DiscoverRoutes
{
    /**
     * A date bound loose enough to be wrong only in the safe direction.
     *
     * Departure dates are calendar dates in the route's own zone and IANA
     * offsets span roughly a day, so subtracting one whole day from the UTC
     * date cannot drop a route that is still upcoming anywhere. It is a
     * pre-filter, not an answer: it keeps years of historical one-offs out of
     * the scan without deciding anything the domain decides.
     */
    private const CONSERVATIVE_DATE_SLACK_DAYS = 1;

    public function __invoke(
        Account $searcher,
        string $originPlaceId,
        string $destinationPlaceId,
        ?RouteCursor $cursor = null,
        int $limit = 20,
        ?CarbonImmutable $now = null,
    ): DiscoveryPage {
        $now ??= CarbonImmutable::now();

        // One more than the caller asked for. Finding it is what distinguishes
        // "there is another page" from "that was everything".
        $wanted = $limit + 1;

        /** @var list<DiscoveredRoute> $eligible */
        $eligible = [];
        $scan = $cursor;

        while (count($eligible) < $wanted) {
            $candidates = $this
                ->candidates($searcher, $originPlaceId, $destinationPlaceId, $now, $scan)
                ->take($wanted)
                ->get();

            if ($candidates->isEmpty()) {
                break;
            }

            foreach ($candidates as $route) {
                $found = $this->eligible($route, $now);

                if ($found instanceof DiscoveredRoute) {
                    $eligible[] = $found;
                }
            }

            // Advance past everything just examined, eligible or not. This is
            // the SCAN position, and it is not what the caller is handed. The
            // window is non-empty by the check above, so there is always a last
            // row to advance to.
            $lastScanned = $candidates->last();
            $scan = new RouteCursor(
                $lastScanned->created_at,
                $lastScanned->id,
                RouteCursor::DISCOVERY,
            );

            // A short window means the candidate set is exhausted; asking again
            // would return nothing.
            if ($candidates->count() < $wanted) {
                break;
            }
        }

        $hasMore = count($eligible) > $limit;
        $page = array_slice($eligible, 0, $limit);
        $last = $page === [] ? null : $page[count($page) - 1];

        return new DiscoveryPage(
            $page,
            $hasMore && $last instanceof DiscoveredRoute
                ? new RouteCursor(
                    $last->route->created_at,
                    $last->route->id,
                    RouteCursor::DISCOVERY,
                )
                : null,
        );
    }

    /**
     * The rows worth examining: everything structurally eligible, in order.
     *
     * @return Builder<Route>
     */
    private function candidates(
        Account $searcher,
        string $originPlaceId,
        string $destinationPlaceId,
        CarbonImmutable $now,
        ?RouteCursor $from,
    ): Builder {
        $query = Route::query()
            ->with(['originPlace', 'destinationPlace', 'account.profile'])
            // A member discovers other people's journeys; their own are in My
            // Routes.
            ->where('account_id', '!=', $searcher->id)
            ->where('status', RouteStatus::Published->value)
            ->where('origin_place_id', $originPlaceId)
            ->where('destination_place_id', $destinationPlaceId)
            // A subquery rather than a join: it cannot multiply a row, so a
            // window is exactly as wide as it looks. Excluded here rather than
            // afterwards so a route nobody can be named for never occupies a
            // slot on somebody's page.
            ->whereHas('account.profile')
            ->where(function (Builder $q) use ($now): void {
                // A recurring commute has no date to be behind us.
                $q->where('recurrence', Recurrence::Weekdays->value)
                    ->orWhere(
                        'departure_date',
                        '>=',
                        $now->utc()->subDays(self::CONSERVATIVE_DATE_SLACK_DAYS)->toDateString(),
                    );
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($from instanceof RouteCursor) {
            // The tuple comparison PostgreSQL understands directly, which is
            // exactly the ordering above.
            $query->whereRaw(
                '(routes.created_at, routes.id) < (?, ?)',
                [$from->createdAt, $from->id],
            );
        }

        return $query;
    }

    /** The candidate paired with its driver, or null if the clock rejects it. */
    private function eligible(Route $route, CarbonImmutable $now): ?DiscoveredRoute
    {
        if ($route->departureState($now) !== DepartureState::Upcoming) {
            return null;
        }

        $driver = $route->account->profile;

        // `whereHas` guarantees one exists; this satisfies the type checker and
        // would catch a future query that dropped the constraint.
        return $driver instanceof Profile
            ? new DiscoveredRoute($route, $driver)
            : null;
    }
}
