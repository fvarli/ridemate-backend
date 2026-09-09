<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\SeatRequest;
use App\Support\KeysetCursor;
use Carbon\CarbonImmutable;

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
    public function __construct(private readonly SeatRequestViews $views) {}

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
            $requests[] = $this->views->own($request, $now);
        }

        $last = $page->last();

        return new SeatRequestPage(
            $requests,
            $hasMore && $last instanceof SeatRequest
                ? new KeysetCursor($last->created_at, $last->id, self::CURSOR)
                : null,
        );
    }
}
