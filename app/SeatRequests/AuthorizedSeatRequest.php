<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\Route;
use App\Models\SeatRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Finding the one request an actor is allowed to touch, and locking it.
 *
 * AUTHORIZATION IS IN THE QUERY, NOT AFTER IT
 *
 * Every lookup here scopes to the actor first and applies `FOR UPDATE` to that
 * scoped query, so a caller can only ever take a lock on a row they are
 * entitled to act on. Fetching by id and checking ownership afterwards would
 * mean an unauthorized caller could hold a lock on somebody else's request for
 * the length of a transaction — briefly, but for a resource that is none of
 * their business, and on a row whose id they only had to guess.
 *
 * A miss is a `ModelNotFoundException` whatever the cause — no such request, or
 * one belonging to somebody else. `docs/api-conventions.md`: a resource the
 * caller may not see answers 404, because distinguishing it from 403 tells them
 * the resource exists.
 */
final class AuthorizedSeatRequest
{
    /** The passenger's own asking. */
    public function forPassenger(Account $passenger, string $requestId): SeatRequest
    {
        return $this->lock(
            SeatRequest::query()->where('account_id', $passenger->id),
            $requestId,
        );
    }

    /**
     * A request on a journey this driver published.
     *
     * Ownership is derived through the route rather than stored on the request
     * — `routes.account_id` is the single answer to whose journey it is — so the
     * scope is an EXISTS against it. `FOR UPDATE` still locks the seat request
     * row and only that row; the subquery reads the route without locking it,
     * which matters because accept takes the route lock deliberately and second.
     */
    public function forDriver(Account $driver, string $requestId): SeatRequest
    {
        return $this->lock(
            SeatRequest::query()->whereHas(
                'route',
                fn (Builder $route): Builder => $route->where('account_id', $driver->id),
            ),
            $requestId,
        );
    }

    /**
     * @param  Builder<SeatRequest>  $scoped
     */
    private function lock(Builder $scoped, string $requestId): SeatRequest
    {
        $request = $scoped->where('id', $requestId)->lockForUpdate()->first();

        if (! $request instanceof SeatRequest) {
            throw (new ModelNotFoundException)->setModel(SeatRequest::class, [$requestId]);
        }

        return $request;
    }

    /**
     * The journey a request is about, locked as the capacity owner.
     *
     * Taken only after the request row is already held, which is the order the
     * whole system uses. See the lock-order note in `AcceptSeatRequest`.
     */
    public function lockRouteOf(SeatRequest $request): Route
    {
        return Route::query()->lockForUpdate()->findOrFail($request->route_id);
    }
}
