<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\Route;
use App\Models\SeatRequest;
use App\Routes\DepartureState;
use App\Routes\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A passenger asks a driver for a seat.
 *
 * IDENTITY IS RESOLVED BEFORE ANYTHING MUTABLE
 *
 * The supplied UUID is the idempotency key, and this is the order that makes it
 * one. If eligibility ran first, a retry after a lost response would be judged
 * against a world that had moved on: the driver cancels between the original
 * request and the retry, the retry is refused, and the passenger is told their
 * asking failed when it is sitting in the database. A retry must be
 * indistinguishable from the first attempt succeeding, so the id is looked up
 * first and an existing resource is returned whatever the route has since
 * become and whatever the request's own status now is.
 *
 * Only when the id identifies nothing does this become a create, and only then
 * does current eligibility matter.
 *
 * THE ROUTE IS LOCKED BEFORE THE DECISION, NOT AFTER IT
 *
 * Without the lock: this reads the route as published, `CancelRoute` commits a
 * cancellation, and this then commits a pending request onto a withdrawn
 * journey. The lock is the same row `CancelRoute` takes, so the two serialize
 * and whichever runs second sees what the first did.
 *
 * Every check is re-read under that lock. A value read before it is a value
 * that could already be stale.
 *
 * LOCK ORDER
 *
 * A new create locks the ROUTE only — the `find()` above touches an id that by
 * definition has no row, and PostgreSQL takes no lock for one that is not
 * there. An existing-id retry locks that REQUEST only and returns without ever
 * reaching for a route. Accept (a later commit) locks request then route.
 * Nothing anywhere locks route then request, so the two-resource order in this
 * system is request → route and a deadlock cycle is unreachable. Keep it that
 * way.
 *
 * CAPACITY IS NOT CONSULTED
 *
 * Asking is not taking. Seats are bound when a driver accepts, in the same
 * transaction that counts them; refusing to ask because the seats are spoken
 * for would decide on the driver's behalf.
 */
final class RequestSeat
{
    public function __invoke(
        Account $passenger,
        string $requestId,
        string $routeId,
        ?CarbonImmutable $now = null,
    ): SeatRequested {
        return DB::transaction(function () use ($passenger, $requestId, $routeId, $now): SeatRequested {
            $existing = SeatRequest::query()->lockForUpdate()->find($requestId);

            if ($existing instanceof SeatRequest) {
                // Deliberately terminal: no profile check, no route lookup, no
                // eligibility. See the note above.
                return $this->resolveExisting($existing, $passenger, $routeId);
            }

            if (! $passenger->profile()->exists()) {
                throw SeatRequestRefused::profileRequired();
            }

            $route = $this->eligibleRoute($passenger, $routeId, $now);

            try {
                // A SAVEPOINT. PostgreSQL aborts the transactional scope a
                // failed insert runs in, so without this the outer transaction
                // would be unusable and the recovery below could not query at
                // all. Laravel opens a savepoint for a nested transaction and
                // rolls back to it, leaving the outer one — and the route lock
                // — alive.
                $request = DB::transaction(
                    fn (): SeatRequest => $this->insert($passenger, $requestId, $route, $now),
                );
            } catch (UniqueConstraintViolationException $violation) {
                return $this->resolveRace($violation, $passenger, $requestId, $route);
            }

            return new SeatRequested($request, wasAlreadyRequested: false);
        });
    }

    /**
     * What a supplied id that already exists means.
     *
     * The same member asking again about the same journey is the same asking,
     * however it has since been answered. Anything else is an id collision, and
     * the refusal says only that — no owner, no route, no status.
     */
    private function resolveExisting(
        SeatRequest $existing,
        Account $passenger,
        string $routeId,
    ): SeatRequested {
        if ($existing->account_id === $passenger->id && $existing->route_id === $routeId) {
            // Nothing is written, not even a timestamp: a retry is one asking
            // arriving twice, not a second event.
            return new SeatRequested($existing, wasAlreadyRequested: true);
        }

        throw SeatRequestRefused::idAlreadyUsed();
    }

    /**
     * The route, locked, re-read, and eligible — or nothing the caller may see.
     */
    private function eligibleRoute(Account $passenger, string $routeId, ?CarbonImmutable $now): Route
    {
        $route = Route::query()->lockForUpdate()->find($routeId);

        if (! $route instanceof Route) {
            $this->noSuchRoute($routeId);
        }

        // Ownership first, and it is safe to be specific: the caller owns this
        // journey, so nothing is disclosed that they did not publish.
        if ($route->account_id === $passenger->id) {
            throw SeatRequestRefused::ownRoute();
        }

        // Cancelled and departed journeys are not passenger-visible anywhere —
        // discovery excludes both — so they answer the way an unknown id does.
        // A distinguishable refusal here would confirm which ids exist.
        if (! $route->isPublished() || $route->departureState($now) === DepartureState::Past) {
            $this->noSuchRoute($routeId);
        }

        if ($route->recurrence !== Recurrence::Once) {
            // Safe to name: a published, upcoming weekday route is publicly
            // discoverable, so its existence is not a secret.
            throw SeatRequestRefused::recurringRouteUnsupported();
        }

        return $route;
    }

    private function insert(
        Account $passenger,
        string $requestId,
        Route $route,
        ?CarbonImmutable $now,
    ): SeatRequest {
        $request = new SeatRequest;
        // The client's id, assigned before save so HasUuids leaves it alone.
        // This is the idempotency key; a server-minted one would make every
        // retry a new asking.
        $request->id = $requestId;
        $request->route_id = $route->id;
        $request->account_id = $passenger->id;
        $request->status = SeatRequestStatus::Pending;
        $request->requested_at = $now ?? CarbonImmutable::now();
        $request->save();

        return $request;
    }

    /**
     * Which uniqueness broke, decided by reading the table rather than the error.
     *
     * Two constraints can fail here — the primary key when the same id is
     * created twice at once, and the lifetime `(route_id, account_id)` unique
     * when the same member asks twice under different ids. PostgreSQL localizes
     * its messages and the driver's column hints vary, so neither is treated as
     * the source of truth: the rows themselves say what happened, and they
     * cannot be ambiguous.
     *
     * A violation that neither row explains is a defect, not a race, and is
     * rethrown. Converting an unexplained integrity failure into a business
     * conflict would answer `409` for a bug.
     */
    private function resolveRace(
        UniqueConstraintViolationException $violation,
        Account $passenger,
        string $requestId,
        Route $route,
    ): SeatRequested {
        $byId = SeatRequest::query()->find($requestId);

        if ($byId instanceof SeatRequest) {
            // Same answer as a retry that never raced: identity decides.
            return $this->resolveExisting($byId, $passenger, $route->id);
        }

        $byMember = SeatRequest::query()
            ->where('route_id', $route->id)
            ->where('account_id', $passenger->id)
            ->first();

        if ($byMember instanceof SeatRequest) {
            throw SeatRequestRefused::alreadyRequested($byMember);
        }

        throw $violation;
    }

    /**
     * The non-disclosing answer.
     *
     * A `ModelNotFoundException` rather than a refusal reason, so a 404 cannot
     * acquire a distinguishable machine string by somebody later adding one.
     *
     * Throws rather than returns, so every caller is a dead end and PHPStan
     * knows it.
     */
    private function noSuchRoute(string $routeId): never
    {
        throw (new ModelNotFoundException)->setModel(Route::class, [$routeId]);
    }
}
