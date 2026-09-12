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
 * there. An existing-id retry locks that REQUEST only: it reads the route
 * unlocked to learn which dated journey the stored asking is for, and takes no
 * lock on it, so it still cannot put a `route → request` order into the system.
 * Accept locks request then route. Nothing anywhere LOCKS route then request,
 * so the two-resource order in this system is request → route and a deadlock
 * cycle is unreachable. Keep it that way.
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
     * The same member asking again about the same DATED journey is the same
     * asking, however it has since been answered. Anything else is an id
     * collision, and the refusal says only that — no owner, no route, no date,
     * no status.
     *
     * WHY THE DATE IS PART OF THE IDENTITY
     *
     * A journey is a route on a date, so the same id carrying a different date
     * describes a different asking. Without this term a client that reused one
     * id for Monday and then Tuesday would be handed Monday's row back as a
     * successful replay — the wrong asking, reported as a success. Nothing can
     * produce that yet, because both recurrence guards stand and a one-off
     * route has one date; it is written now so 16b cannot introduce it
     * silently.
     *
     * WHY THE ROUTE IS READ HERE, AND WHY THAT IS STILL SAFE
     *
     * The date is the route's while every journey is one-off, so learning it
     * needs the route. The read is UNLOCKED, so the retry path still takes no
     * route lock and the `request → route` order is untouched. It re-runs no
     * eligibility, so a retry still succeeds on a journey since cancelled or
     * departed. And it happens only once the account and route already match,
     * where the route certainly exists — a request references it and the
     * foreign key cascades, so an orphan is unrepresentable.
     *
     * In 16b the caller names the date and this read disappears again.
     */
    private function resolveExisting(
        SeatRequest $existing,
        Account $passenger,
        string $routeId,
        ?Route $locked = null,
    ): SeatRequested {
        if ($existing->account_id !== $passenger->id || $existing->route_id !== $routeId) {
            throw SeatRequestRefused::idAlreadyUsed();
        }

        // `$locked` is passed by the race path, which is already holding this
        // route; everywhere else the row is read fresh and without a lock.
        $route = $locked ?? Route::query()->find($routeId);

        if (! $route instanceof Route) {
            $this->noSuchRoute($routeId);
        }

        if ($existing->service_date->toDateString() !== $route->soleServiceDate()->toDateString()) {
            throw SeatRequestRefused::idAlreadyUsed();
        }

        // Nothing is written, not even a timestamp: a retry is one asking
        // arriving twice, not a second event.
        return new SeatRequested($existing, wasAlreadyRequested: true);
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
        // Which dated journey is being asked about. Derived rather than named
        // by the caller, because a one-off route has exactly one and the
        // recurrence guard above has already refused everything else.
        $request->service_date = $route->soleServiceDate();
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
            // Same answer as a retry that never raced: identity decides. The
            // route is already locked here, so it is handed over rather than
            // read again.
            return $this->resolveExisting($byId, $passenger, $route->id, $route);
        }

        // Scoped to the dated journey, not merely to the route: a member may
        // hold askings on several dates of one plan, and the refusal must name
        // the one that collided rather than whichever row is found first.
        $byMember = SeatRequest::query()
            ->where('route_id', $route->id)
            ->where('service_date', $route->soleServiceDate()->toDateString())
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
