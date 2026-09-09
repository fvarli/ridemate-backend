<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\Profile;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Support\KeysetCursor;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Everything this member has asked for, newest first.
 *
 * HISTORY, NOT A FEED
 *
 * Nothing is filtered by what discovery would show today. A request on a
 * journey that was later cancelled stays here, and so does one on a journey
 * that has departed — this is the only surface that tells a member what they
 * asked and what came of it, and quietly dropping rows because the route moved
 * on would erase their own history. Every status is included for the same
 * reason: a declined request is an answer, not an absence.
 *
 * The journey's current state travels alongside rather than replacing anything.
 * See `OwnSeatRequest`.
 */
final class ListMySeatRequests
{
    /** This surface's cursors, refused by every other feed. */
    public const CURSOR = 'rm.seatrequests.v1';

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * @return SeatRequestPage<OwnSeatRequest>
     */
    public function __invoke(
        Account $passenger,
        ?KeysetCursor $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
        ?CarbonImmutable $now = null,
    ): SeatRequestPage {
        $query = SeatRequest::query()
            // Loaded deliberately: without this every row would fetch its route,
            // both places and the driver's profile one at a time.
            ->with([
                'route.originPlace',
                'route.destinationPlace',
                'route.account.profile',
            ])
            ->where('account_id', $passenger->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($cursor instanceof KeysetCursor) {
            // PostgreSQL compares the tuple, which is exactly the ordering
            // above — one predicate rather than a nested OR.
            $query->whereRaw('(created_at, id) < (?, ?)', [$cursor->createdAt, $cursor->id]);
        }

        $rows = $query->take($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        $requests = [];
        foreach ($page as $request) {
            $requests[] = $this->own($request, $now);
        }

        $last = $page->last();

        return new SeatRequestPage(
            $requests,
            $hasMore && $last instanceof SeatRequest
                ? new KeysetCursor($last->created_at, $last->id, self::CURSOR)
                : null,
        );
    }

    private function own(SeatRequest $request, ?CarbonImmutable $now): OwnSeatRequest
    {
        // No guard on the route itself: the foreign key is NOT NULL and
        // cascades, so a request without its journey cannot exist, and a dead
        // check would only read as if it could.
        $route = $request->route;

        $driver = $route->account->profile;

        if (! $driver instanceof Profile) {
            // A route can only be discovered — and so only requested — when its
            // owner has a profile. Rather than synthesise a name or blank
            // initials for a driver who has none, this fails: a fabricated
            // identity on the screen that names a stranger is the worst
            // available outcome.
            throw new RuntimeException("Route {$route->id} has no driver profile.");
        }

        return new OwnSeatRequest(
            $request,
            $route,
            $route->departureState($now),
            $driver,
        );
    }
}
