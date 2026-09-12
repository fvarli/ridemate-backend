<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * When a review may be read by the member it is about.
 *
 * THE RULE, IN ONE PLACE
 *
 *     released = a counterpart review exists
 *             OR now >= trip.completed_at + 14 days
 *
 * Neither half is stored. A `released_at` column would be a derived fact kept a
 * second time, and the flag that flipped it would need a scheduler — for a
 * question nobody asks until somebody reads. A review does not need to have
 * been released on time; it needs to be released by the time it is read.
 *
 * WHY IT IS A SQL PREDICATE AND NOT A FILTER IN PHP
 *
 * Because a hidden review must not consume a page slot, and must not be what a
 * cursor names. Fetching candidates and dropping them afterwards would return
 * short pages, would make `next_cursor` point at a row the caller never saw,
 * and would leak the existence of a hidden counterpart through the shape of the
 * page. Pushed into the query, a hidden row is not a candidate at all: it never
 * enters the result set, the limit counts only what the caller will see, and
 * the cursor can only ever name a returned row.
 *
 * Phase 12's discovery scans until a page fills because its filter cannot be
 * expressed in SQL. This one can, so it is.
 */
final class ReleasedReviews
{
    private function __construct() {}

    /**
     * Narrows [$reviews] to the rows their subject is entitled to read.
     *
     * Expects the query to have joined `seat_requests`, `routes` and `trips`,
     * which the caller needs anyway to know who the subject is.
     */
    /**
     * @param  Builder<Review>  $reviews
     * @return Builder<Review>
     */
    public static function only(Builder $reviews, CarbonImmutable $now): Builder
    {
        return $reviews->where(static function (Builder $released) use ($now): void {
            $released
                ->whereExists(static function (BuilderContract $counterpart): void {
                    $counterpart
                        ->selectRaw('1')
                        ->from('reviews as counterpart')
                        ->whereColumn('counterpart.seat_request_id', 'reviews.seat_request_id')
                        // The other side of the same relationship. Not "any
                        // other row": a seat request holds at most two reviews
                        // and the unique constraint says which.
                        ->whereColumn('counterpart.reviewer_role', '!=', 'reviews.reviewer_role');
                })
                // Inclusive: the deadline itself releases. It is the mirror of
                // submission, which closes at the same instant — so there is no
                // moment when a review can neither be written nor read.
                ->orWhereRaw('? >= trips.completed_at + ? * interval \'1 day\'', [
                    $now,
                    ReviewWindow::DAYS,
                ]);
        });
    }
}
