<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Account;
use App\Models\SeatRequest;

/**
 * Who the two parties to a relationship are, derived rather than stored.
 *
 * THIS IS WHAT MAKES THE SCHEMA SAFE
 *
 * `reviews` holds no account column. That is only defensible because there is
 * exactly one way to work out who wrote a review and who it is about, and this
 * is it:
 *
 *     passenger = seat_requests.account_id
 *     driver    = routes.account_id, for seat_requests.route_id
 *
 * A row whose reviewer is neither party, or whose role disagrees with its
 * account, cannot be written because there is nowhere to write it. Storing the
 * ids instead would move that guarantee into whichever code path happened to
 * set them.
 *
 * READ-ONLY. Nothing here writes, locks or transacts; it answers questions
 * about rows that already exist.
 */
final readonly class ReviewParticipants
{
    private function __construct(
        public Account $driver,
        public Account $passenger,
    ) {}

    /**
     * The two parties to [$request].
     *
     * The route's owner and the member who asked. Both are loaded through the
     * relations the request already has, so a caller that eager-loaded them
     * pays for no further query.
     */
    public static function of(SeatRequest $request): self
    {
        return new self(
            driver: $request->route->account,
            passenger: $request->passenger,
        );
    }

    /**
     * Which side [$account] is, from ids alone.
     *
     * Needs only the request's `account_id` and its route's — no `Account`
     * loaded on either side. A listing that eager-loaded the route (both of
     * them do) therefore pays nothing per row, where resolving through [of]
     * would fetch the passenger one at a time. Same answer, cheaper question.
     */
    public static function roleIn(SeatRequest $request, Account $account): ?ReviewerRole
    {
        return match ($account->id) {
            $request->route->account_id => ReviewerRole::Driver,
            $request->account_id => ReviewerRole::Passenger,
            default => null,
        };
    }

    /**
     * Which side [$account] is, or null when it is neither.
     *
     * Null is the ordinary answer for a member who is party to nothing here,
     * and the caller turns it into the non-disclosing 404 that every other
     * route-scoped command answers — never into a refusal, which would confirm
     * that the relationship exists.
     */
    public function roleOf(Account $account): ?ReviewerRole
    {
        return match ($account->id) {
            $this->driver->id => ReviewerRole::Driver,
            $this->passenger->id => ReviewerRole::Passenger,
            default => null,
        };
    }

    /** The member a review with this role is about. */
    public function subjectOf(ReviewerRole $role): Account
    {
        return match ($role) {
            ReviewerRole::Driver => $this->passenger,
            ReviewerRole::Passenger => $this->driver,
        };
    }

    /** The member who wrote a review with this role. */
    public function authorOf(ReviewerRole $role): Account
    {
        return match ($role) {
            ReviewerRole::Driver => $this->driver,
            ReviewerRole::Passenger => $this->passenger,
        };
    }
}
