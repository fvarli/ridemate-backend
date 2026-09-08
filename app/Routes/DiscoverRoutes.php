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
 * EXACT ENDPOINTS, AND NOTHING SPATIAL
 *
 * A route matches when its origin IS the requested origin and its destination
 * IS the requested destination. No radius, no corridor, no distance.
 *
 * That is a decision about honesty rather than effort. The pilot catalogue is
 * five curated meeting points, and the closest two are a kilometre apart: a
 * radius small enough to be meaningful returns exactly what an equality check
 * returns, and one large enough to change the answer would start merging
 * places a driver deliberately chose between. A straight line between two
 * points is not a driving corridor either, and drawing one would be inventing
 * geometry the product does not have. Real corridor matching needs routing
 * data from somewhere; until it exists, equality is the strongest true thing.
 *
 * The `point` column on places stays where it is, unread and unindexed. It is
 * there for the phase that can use it truthfully.
 *
 * DIRECTION IS PART OF THE MATCH
 *
 * Kadıköy to Levent is not Levent to Kadıköy. Returning the reverse would offer
 * somebody a journey going the wrong way, which is worse than no result.
 *
 * WHY THE CLOCK IS READ IN PHP AND NOT IN SQL
 *
 * Whether a departure is still ahead of a member is decided by
 * RouteDeparture::state, in the route's own timezone, and that is the only
 * implementation of the rule. Writing the comparison again in SQL would create
 * a second one, and the two would eventually disagree about a route on the day
 * it departs.
 *
 * So SQL applies a deliberately COARSE date bound — one that cannot exclude an
 * eligible row under any offset — and the domain makes the real decision on the
 * rows that come back. The bound exists to stop the query dragging years of
 * past journeys through the keyset, not to answer the question.
 */
final class DiscoverRoutes
{
    /**
     * A date bound loose enough to be wrong only in the safe direction.
     *
     * Departure dates are stored as calendar dates in the route's own zone, and
     * IANA offsets span roughly a day. Subtracting one whole day from the UTC
     * date therefore cannot drop a route that is still upcoming anywhere, while
     * still excluding everything genuinely historical.
     */
    private const CONSERVATIVE_DATE_SLACK_DAYS = 1;

    /**
     * @param  string  $originPlaceId  the exact place a member wants to leave from
     * @param  string  $destinationPlaceId  the exact place they want to reach
     */
    public function __invoke(
        Account $searcher,
        string $originPlaceId,
        string $destinationPlaceId,
        ?RouteCursor $cursor = null,
        int $limit = 20,
        ?CarbonImmutable $now = null,
    ): DiscoveryPage {
        $now ??= CarbonImmutable::now();

        $query = $this->eligible($searcher, $originPlaceId, $destinationPlaceId, $now);

        if ($cursor instanceof RouteCursor) {
            // The tuple comparison PostgreSQL understands directly, which is
            // exactly the ordering below — one predicate rather than the nested
            // OR that writing it by hand would need.
            $query->whereRaw(
                '(routes.created_at, routes.id) < (?, ?)',
                [$cursor->createdAt, $cursor->id],
            );
        }

        // One more than asked for, which is how the page learns whether
        // anything follows it without a second query.
        $rows = $query->take($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $window = $rows->take($limit);

        // The cursor names a position in the DATABASE's ordering, taken before
        // the domain filter below. Deriving it from the surviving rows instead
        // would skip everything the filter dropped at the end of a page.
        $last = $window->last();

        return new DiscoveryPage(
            // array_values, because `present` promises a list and a Collection
            // makes no promise about its keys — true today by construction and
            // not something this should depend on.
            $this->present(array_values($window->all()), $now),
            $hasMore && $last instanceof Route
                ? new RouteCursor($last->created_at, $last->id, RouteCursor::DISCOVERY)
                : null,
        );
    }

    /**
     * @return Builder<Route>
     */
    private function eligible(
        Account $searcher,
        string $originPlaceId,
        string $destinationPlaceId,
        CarbonImmutable $now,
    ): Builder {
        return Route::query()
            ->with(['originPlace', 'destinationPlace', 'account.profile'])
            // A member discovers other people's journeys. Their own are in My
            // Routes, and offering somebody a seat in their own car is noise at
            // best and a bug report at worst.
            ->where('account_id', '!=', $searcher->id)
            ->where('status', RouteStatus::Published->value)
            ->where('origin_place_id', $originPlaceId)
            ->where('destination_place_id', $destinationPlaceId)
            // whereHas rather than a join: an owner has at most one profile, so
            // a join could not multiply rows today — but it could the moment
            // anything else is joined, and a subquery cannot duplicate a row by
            // construction. The page size stays the page size.
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
    }

    /**
     * Applies the canonical departure rule and pairs each route with its driver.
     *
     * @param  list<Route>  $routes
     * @return list<DiscoveredRoute>
     */
    private function present(array $routes, CarbonImmutable $now): array
    {
        $discovered = [];

        foreach ($routes as $route) {
            if ($route->departureState($now) !== DepartureState::Upcoming) {
                continue;
            }

            $driver = $route->account->profile;

            // `whereHas` guarantees one exists; this satisfies the type checker
            // and would catch a future query that dropped the constraint.
            if (! $driver instanceof Profile) {
                continue;
            }

            $discovered[] = new DiscoveredRoute($route, $driver);
        }

        return $discovered;
    }
}
