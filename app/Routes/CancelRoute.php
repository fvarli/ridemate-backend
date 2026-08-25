<?php

declare(strict_types=1);

namespace App\Routes;

use App\Models\Account;
use App\Models\Route;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a journey the member published.
 *
 * NATURALLY IDEMPOTENT, WHICH IS WHY IT NEEDS NO MECHANISM
 *
 * The transition names the state it wants rather than the change it makes, so
 * running it twice is not a second cancellation — it is the same one, observed
 * again. A route that is already cancelled is returned untouched: no write, no
 * new `cancelled_at`, not even a bumped `updated_at`. There is no idempotency
 * key here because there is nothing for one to protect.
 *
 * WHY A PAST JOURNEY CANNOT BE CANCELLED
 *
 * Not an authorisation question — the owner is still the owner. Cancelling a
 * journey that already departed would rewrite what happened rather than change
 * what is going to, and the member's list would stop describing their week. So
 * it is refused, and the row is left exactly as it was.
 *
 * OWNERSHIP IS PART OF THE LOOKUP
 *
 * The query filters on the account, so a route belonging to somebody else is
 * simply not found. That is deliberate: deciding ownership after finding the
 * row would mean two different refusals, and the difference between them would
 * tell a stranger that the route exists.
 */
final class CancelRoute
{
    /**
     * @throws ModelNotFoundException when the route is not this member's.
     * @throws RouteCancellationRefused when the journey has already departed.
     */
    public function __invoke(Account $member, string $routeId, ?CarbonImmutable $now = null): Route
    {
        return DB::transaction(function () use ($member, $routeId, $now): Route {
            $route = Route::query()
                ->where('account_id', $member->id)
                ->lockForUpdate()
                ->findOrFail($routeId);

            if (! $route->isPublished()) {
                // Already cancelled. Returning it unchanged IS the idempotent
                // answer; writing anything here would make a retry a second
                // event in a history that only had one.
                return $route->load(['originPlace', 'destinationPlace']);
            }

            if (! $route->isCancellable($now)) {
                throw RouteCancellationRefused::departureHasPassed();
            }

            $route->status = RouteStatus::Cancelled;
            $route->cancelled_at = $now ?? CarbonImmutable::now();
            $route->save();

            return $route->load(['originPlace', 'destinationPlace']);
        });
    }
}
