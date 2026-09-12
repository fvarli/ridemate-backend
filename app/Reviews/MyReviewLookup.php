<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Account;
use App\Models\Review;
use App\Models\SeatRequest;
use Illuminate\Support\Collection;

/**
 * What the caller has already said about relationships they are party to.
 *
 * ONE OWNER, SO TWO SURFACES CANNOT ANSWER DIFFERENTLY
 *
 * Both the passenger's own askings and a driver's incoming list will carry a
 * `my_review`, and the role differs between them — the same member is the
 * passenger on one surface and the driver on the other. Working that out in two
 * controllers is how the two start to disagree, so it is worked out here.
 *
 * READS IN ONE QUERY, NOT ONE PER ROW
 *
 * A page of twenty askings must not become twenty-one reads. The lesson
 * `my_seat_request` taught in Phase 12.
 *
 * IT NEVER LOOKS AT THE COUNTERPART
 *
 * Only rows whose `reviewer_role` is the caller's own are fetched. A caller
 * cannot tell "they have not reviewed me" from "they have, and I may not see it
 * yet", because this never asks the question — and asking it would be the leak,
 * however carefully the answer were then discarded.
 */
final class MyReviewLookup
{
    /**
     * The caller's own review of [$request], or null.
     */
    public function for(SeatRequest $request, Account $caller): ?MyReview
    {
        $role = ReviewParticipants::of($request)->roleOf($caller);

        if (! $role instanceof ReviewerRole) {
            return null;
        }

        return MyReview::of(
            Review::query()
                ->where('seat_request_id', $request->id)
                ->where('reviewer_role', $role->value)
                ->first(),
        );
    }

    /**
     * The caller's own reviews of many relationships, keyed by seat request id.
     *
     * The role is fixed across a page — a caller reading their own askings is
     * the passenger on every one, and a driver reading one journey's incoming
     * requests is the driver on every one — but it is derived per row anyway,
     * because a surface that mixed the two would otherwise read the wrong side
     * silently.
     *
     * @param  iterable<SeatRequest>  $requests
     * @return array<string, MyReview>
     */
    public function forMany(iterable $requests, Account $caller): array
    {
        /** @var array<string, ReviewerRole> $roles */
        $roles = [];

        foreach ($requests as $request) {
            $role = ReviewParticipants::of($request)->roleOf($caller);

            if ($role instanceof ReviewerRole) {
                $roles[$request->id] = $role;
            }
        }

        if ($roles === []) {
            return [];
        }

        /** @var Collection<int, Review> $rows */
        $rows = Review::query()
            ->whereIn('seat_request_id', array_keys($roles))
            ->get();

        $mine = [];
        foreach ($rows as $review) {
            // The counterpart's row may come back in the same fetch; it is
            // dropped here and never inspected, so nothing about it can reach a
            // caller through this projection.
            if (($roles[$review->seat_request_id] ?? null) !== $review->reviewer_role) {
                continue;
            }

            $projected = MyReview::of($review);

            if ($projected instanceof MyReview) {
                $mine[$review->seat_request_id] = $projected;
            }
        }

        return $mine;
    }
}
