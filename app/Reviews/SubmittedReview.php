<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Review;

/**
 * The outcome of submitting, with the one distinction HTTP will need.
 *
 * `wasAlreadySubmitted` separates a review that has just been written from a
 * retry of one that already landed. Both are successes and both describe the
 * same row; only the second wrote nothing. Mirrors `App\Trips\StartedTrip` and
 * `App\SeatRequests\SeatRequested`.
 */
final readonly class SubmittedReview
{
    public function __construct(
        public Review $review,
        public bool $wasAlreadySubmitted,
    ) {}
}
