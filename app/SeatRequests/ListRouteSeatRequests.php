<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Support\KeysetCursor;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Who has asked for a seat on one of the caller's journeys, newest first.
 *
 * OWNERSHIP IS THE QUERY
 *
 * The route is fetched scoped to the caller, so a driver asking about somebody
 * else's journey gets the same answer as one asking about a journey that does
 * not exist. `docs/api-conventions.md`: distinguishing the two tells them the
 * resource is real.
 *
 * Like the passenger's own history, this is not filtered by the journey's
 * current state — a driver who cancelled still needs to see, and be able to
 * decline, what people asked before they did.
 */
final class ListRouteSeatRequests
{
    public function __construct(private readonly SeatRequestViews $views) {}

    /** This surface's cursors, refused by every other feed. */
    public const CURSOR = 'rm.routerequests.v1';

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * @return SeatRequestPage<IncomingSeatRequest>
     */
    public function __invoke(
        Account $driver,
        string $routeId,
        ?KeysetCursor $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
    ): SeatRequestPage {
        $route = Route::query()
            ->where('account_id', $driver->id)
            ->find($routeId);

        if (! $route instanceof Route) {
            throw (new ModelNotFoundException)->setModel(Route::class, [$routeId]);
        }

        $query = SeatRequest::query()
            // One query for every passenger profile rather than one per row.
            ->with(['passenger.profile'])
            ->where('route_id', $route->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($cursor instanceof KeysetCursor) {
            $query->whereRaw('(created_at, id) < (?, ?)', [$cursor->createdAt, $cursor->id]);
        }

        $rows = $query->take($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        $requests = [];
        foreach ($page as $request) {
            $requests[] = $this->views->incoming($request);
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
