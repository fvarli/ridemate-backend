<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Account;
use App\Models\Review;
use App\Models\SeatRequest;
use App\Models\Trip;
use App\Trips\TripStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One member rating one completed relationship.
 *
 * WHAT THIS COMMAND DOES NOT ASSERT
 *
 * That the passenger boarded, that the driver picked anybody up, or that a
 * journey happened in the world. A completed trip is Phase 14's lifecycle fact
 * and nothing more: a driver pressed Complete. Eligibility here is the narrowest
 * rule the service can actually check, and it is not evidence.
 *
 * IDENTITY BEFORE MUTABLE ELIGIBILITY
 *
 * The supplied id is resolved before the seat request's status, the trip's
 * state or the clock. A review that landed a minute before the window closed
 * must keep replaying afterwards — checking the deadline first would make a
 * command that already succeeded begin to fail, which is exactly the defect the
 * same ordering prevents in `App\SeatRequests\RequestSeat` and
 * `App\Trips\StartTrip`.
 *
 * THE ROLE IS DERIVED, NEVER ACCEPTED
 *
 * A caller does not say which side they are; `ReviewParticipants` works it out
 * from the seat request, and a caller who is neither party gets the same
 * non-disclosing 404 as one naming a request that does not exist. Taking the
 * role from input would let a passenger file a review as the driver.
 *
 * NO LOCK
 *
 * Both preconditions are terminal — an accepted request cannot be withdrawn or
 * declined, a completed trip cannot be aborted — so a read that was true cannot
 * become false underneath this writer. Two simultaneous submissions collide on
 * a unique constraint, which is what it is for, and the collision is classified
 * by re-reading rather than by parsing a constraint name.
 *
 * ROUTE STATUS IS NOT CONSULTED
 *
 * A withdrawn route with an accepted request and a completed trip stays
 * reviewable. Route, seat request and trip are three independent truths, and
 * cancelling a plan says nothing about a journey that was already made.
 */
final class SubmitReview
{
    public function __invoke(
        Account $reviewer,
        string $reviewId,
        string $seatRequestId,
        int $rating,
        ?CarbonImmutable $now = null,
    ): SubmittedReview {
        $request = $this->visibleRequest($reviewer, $seatRequestId);
        $role = ReviewParticipants::of($request)->roleOf($reviewer);

        if (! $role instanceof ReviewerRole) {
            // Party to neither side. The same answer as a request that does not
            // exist, so nobody can learn which ids are real by reviewing them.
            $this->noSuchRequest($seatRequestId);
        }

        $existing = Review::query()->find($reviewId);

        if ($existing instanceof Review) {
            // Deliberately terminal: no status check, no trip check, no clock.
            return $this->resolveExisting($existing, $request, $role, $rating);
        }

        $this->refuseDuplicate($request, $role);
        $this->refuseIneligible($request, $now ?? CarbonImmutable::now());

        return $this->create($reviewId, $request, $role, $rating);
    }

    /**
     * The seat request, with both parties and the journey it is about.
     *
     * Loaded in one go because every step below needs some part of it, and a
     * caller that is party to nothing must not be told so by a different shape
     * of failure than a caller naming nothing.
     */
    private function visibleRequest(Account $reviewer, string $seatRequestId): SeatRequest
    {
        $request = SeatRequest::query()
            ->with(['route.account', 'route.trip', 'passenger'])
            ->find($seatRequestId);

        if (! $request instanceof SeatRequest) {
            $this->noSuchRequest($seatRequestId);
        }

        return $request;
    }

    /**
     * What an existing id means for a repeated submission.
     *
     * The same review arriving again is that submission observed twice, so it
     * is returned unchanged — nothing is written, not even a timestamp. An id
     * describing anything else is a different review wearing a used name, and
     * saying so is all this can safely tell the caller: the row behind it may
     * be somebody else's.
     */
    private function resolveExisting(
        Review $existing,
        SeatRequest $request,
        ReviewerRole $role,
        int $rating,
    ): SubmittedReview {
        $sameReview = $existing->seat_request_id === $request->id
            && $existing->reviewer_role === $role
            && $existing->rating === $rating;

        if (! $sameReview) {
            throw ReviewRefused::idAlreadyUsed();
        }

        return new SubmittedReview($existing, wasAlreadySubmitted: true);
    }

    /** One review per party per relationship, whatever id it arrives under. */
    private function refuseDuplicate(SeatRequest $request, ReviewerRole $role): void
    {
        $exists = Review::query()
            ->where('seat_request_id', $request->id)
            ->where('reviewer_role', $role->value)
            ->exists();

        if ($exists) {
            throw ReviewRefused::alreadyReviewed();
        }
    }

    /**
     * Why this relationship cannot be rated, if it cannot.
     *
     * THE ORDER IS LOAD-BEARING. The seat request comes first because it is the
     * relationship itself: a request that was declined names no shared journey,
     * and answering `trip_not_completed` for one would be a true-sounding
     * statement about the wrong thing — the trip may well have completed, with
     * somebody else aboard.
     */
    private function refuseIneligible(SeatRequest $request, CarbonImmutable $now): void
    {
        if (! $request->isAccepted()) {
            throw ReviewRefused::seatRequestNotAccepted();
        }

        $trip = $request->route->trip;

        // Covers a journey nobody started, one under way, and one abandoned.
        // `aborted` means a driver moved a started trip to that state — not
        // that the journey did not happen. It is ineligible as a product rule.
        if (! $trip instanceof Trip || $trip->status !== TripStatus::Completed) {
            throw ReviewRefused::tripNotCompleted();
        }

        $completedAt = $trip->completed_at;

        // Unreachable while the shape check holds: a completed trip carries its
        // completion time. Stated rather than assumed, because the alternative
        // is an unanchored window.
        if ($completedAt === null || ReviewWindow::hasClosed($completedAt, $now)) {
            throw ReviewRefused::reviewWindowClosed();
        }
    }

    /**
     * Writes the review, and resolves the race if one happened.
     *
     * The insert runs in its own transaction so a unique violation rolls back
     * to a savepoint rather than poisoning a caller's transaction. PostgreSQL
     * aborts a whole transaction on the first failed statement, so without this
     * the re-reads below could not run — the classification would be replaced
     * by "current transaction is aborted", which says nothing.
     */
    private function create(
        string $reviewId,
        SeatRequest $request,
        ReviewerRole $role,
        int $rating,
    ): SubmittedReview {
        $review = new Review;
        $review->id = $reviewId;
        $review->seat_request_id = $request->id;
        $review->reviewer_role = $role;
        $review->rating = $rating;

        try {
            DB::transaction(static fn () => $review->save());
        } catch (QueryException $collision) {
            return $this->resolveCollision($collision, $request, $role, $rating, $reviewId);
        }

        return new SubmittedReview($review, wasAlreadySubmitted: false);
    }

    /**
     * Which constraint a racing writer tripped, worked out by looking.
     *
     * Deliberately not by reading the constraint name off the exception: that
     * is a database dialect's vocabulary leaking into a domain decision, and it
     * changes with a rename nobody would think to test. Re-reading the two rows
     * that could exist answers the same question from the data.
     */
    private function resolveCollision(
        QueryException $collision,
        SeatRequest $request,
        ReviewerRole $role,
        int $rating,
        string $reviewId,
    ): SubmittedReview {
        $byId = Review::query()->find($reviewId);

        if ($byId instanceof Review) {
            return $this->resolveExisting($byId, $request, $role, $rating);
        }

        $this->refuseDuplicate($request, $role);

        // Neither row exists, so this was not a race on either constraint —
        // the seat request going away under us, for instance. Nothing here
        // understands it, and swallowing it would report a review that is not
        // there.
        throw $collision;
    }

    /**
     * @throws ModelNotFoundException
     */
    private function noSuchRequest(string $seatRequestId): never
    {
        throw (new ModelNotFoundException)->setModel(SeatRequest::class, [$seatRequestId]);
    }
}
