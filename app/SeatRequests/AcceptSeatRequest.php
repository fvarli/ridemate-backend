<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Routes\DepartureState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A driver giving a seat away.
 *
 * The only command in Phase 13 that creates an obligation, and therefore the
 * only one that cares whether the journey is still going to happen.
 *
 * TARGET STATE IS RESOLVED BEFORE THE ROUTE IS TOUCHED
 *
 * A repeated accept of an already-accepted request answers successfully and
 * writes nothing — and it must keep doing so after the journey is cancelled,
 * after it departs, and after the remaining seats are given away. Checking
 * route lifecycle or capacity first would make a retry of a command that
 * already succeeded start failing later, which is the same defect the create
 * path's identity-before-eligibility ordering exists to prevent. The seat was
 * given; a lost response does not un-give it.
 *
 * Only a still-pending request reaches the route at all.
 *
 * LOCK ORDER: REQUEST, THEN ROUTE
 *
 * The request row is already held — scoped to this driver before it was locked,
 * so no caller takes a lock on a request they may not act on. The route lock
 * comes second and always second.
 *
 * Across the whole domain: withdraw and decline lock a request only; a new
 * create locks a route only; an existing-id retry locks a request only and
 * never reaches for a route. This command is the sole path holding both, so
 * `request → route` is the only two-resource order in the system and a deadlock
 * cycle is unreachable. Anything later that takes a route lock and then reaches
 * for an existing request would create one.
 *
 * CAPACITY IS COUNTED INSIDE THE ROUTE LOCK
 *
 * That is what makes the invariant real: two drivers' devices accepting the
 * last seat at the same time serialize behind the same row, and the second sees
 * the first's write. Counting before the lock, or outside the transaction,
 * would be two readers agreeing there is room.
 *
 * Pending requests reserve nothing. A journey may be asked about by twenty
 * people and still have every seat free.
 */
final class AcceptSeatRequest
{
    public function __construct(private readonly AuthorizedSeatRequest $requests) {}

    public function __invoke(
        Account $driver,
        string $requestId,
        ?CarbonImmutable $now = null,
    ): TransitionedSeatRequest {
        return DB::transaction(function () use ($driver, $requestId, $now): TransitionedSeatRequest {
            $request = $this->requests->forDriver($driver, $requestId);

            return match ($request->status) {
                // Terminal states answer here, before any route work. See above.
                SeatRequestStatus::Accepted => new TransitionedSeatRequest(
                    $request,
                    wasAlreadyInTargetState: true,
                ),
                SeatRequestStatus::Declined => throw SeatRequestRefused::alreadyDecided($request),
                SeatRequestStatus::Withdrawn => throw SeatRequestRefused::withdrawn($request),
                SeatRequestStatus::Pending => $this->accept($request, $now),
            };
        });
    }

    private function accept(SeatRequest $request, ?CarbonImmutable $now): TransitionedSeatRequest
    {
        $route = $this->requests->lockRouteOf($request);

        // Re-read under the lock. A value read before it may already be stale,
        // and `CancelRoute` takes this same row.
        if (! $route->isPublished() || $route->departureState($now) === DepartureState::Past) {
            throw SeatRequestRefused::routeUnavailable();
        }

        if ($this->acceptedSeats($route) >= $route->seats_offered) {
            throw SeatRequestRefused::routeFull();
        }

        $request->status = SeatRequestStatus::Accepted;
        $request->decided_at = $now ?? CarbonImmutable::now();
        $request->save();

        return new TransitionedSeatRequest($request, wasAlreadyInTargetState: false);
    }

    /** Counted while the route lock is held, or it is only an opinion. */
    private function acceptedSeats(Route $route): int
    {
        return SeatRequest::query()
            ->where('route_id', $route->id)
            ->where('status', SeatRequestStatus::Accepted->value)
            ->count();
    }
}
