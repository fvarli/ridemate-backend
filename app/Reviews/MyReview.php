<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Review;
use Carbon\CarbonImmutable;

/**
 * What the caller has already said about one relationship.
 *
 * It answers one question — *have I submitted, and what did I submit* — so a
 * screen knows whether to offer the control. Nothing more.
 *
 * IT SAYS NOTHING ABOUT THE OTHER SIDE
 *
 * No counterpart id, rating, submission state or release flag, and no deadline.
 * A caller must not be able to tell "they have not reviewed me" from "they have,
 * and I am not yet entitled to see it" — the two are identical here, and they
 * are identical in storage too, because release is derived rather than recorded.
 *
 * That matters before the caller writes: knowing the other side had already
 * rated them would bias the rating they are about to give, which is the whole
 * reason release waits for both sides or for the window.
 */
final readonly class MyReview
{
    private function __construct(
        public string $id,
        public int $rating,
        public CarbonImmutable $submittedAt,
    ) {}

    /**
     * The caller's own review, or null when they have not written one.
     *
     * Null is the ordinary answer rather than missing data, and it is the same
     * answer whether or not the counterpart has written theirs.
     */
    public static function of(?Review $review): ?self
    {
        if (! $review instanceof Review) {
            return null;
        }

        return new self(
            $review->id,
            $review->rating,
            // A review is never edited, so the row's creation IS the moment the
            // member submitted it. A second column would be the same instant
            // stored twice.
            $review->created_at,
        );
    }
}
