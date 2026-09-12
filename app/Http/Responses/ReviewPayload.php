<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Review;
use App\Reviews\MyReview;

/**
 * What a member sees of a review they wrote themselves.
 *
 * Three fields, and the caller already owns every one of them — this answers
 * "did it land, and what did I say", which is the whole of what a submission
 * response and `my_review` are for.
 *
 * NO `reviewer_role`
 *
 * The caller knows which side they are: they opened the screen from their own
 * asking or their own journey. Publishing it would be a field nobody reads,
 * and one more thing the wire has to keep true.
 *
 * NOTHING ABOUT THE OTHER SIDE
 *
 * No counterpart id, rating, submission state, release flag or deadline. A
 * caller must not be able to tell "they have not reviewed me" from "they have,
 * and I may not see it yet" — knowing would bias the rating they are about to
 * write, which is the whole reason release waits for both sides or the window.
 *
 * `submitted_at` is the row's creation. A review is never edited, so there is
 * one instant and this is it. `Z` rather than an offset, per the Timestamps row
 * in `docs/api-conventions.md`.
 */
final class ReviewPayload
{
    /**
     * @return array{review: array<string, mixed>}
     */
    public static function envelope(Review $review): array
    {
        return ['review' => self::from($review)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function from(Review $review): array
    {
        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'submitted_at' => $review->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * The caller's own review of one relationship, or null.
     *
     * Required and nullable, like `my_seat_request`: a response without the key
     * is not this contract, and reading absence as "not reviewed" would make an
     * older backend claim every relationship is still open to rate.
     *
     * @return array<string, mixed>|null
     */
    public static function mine(?MyReview $mine): ?array
    {
        if (! $mine instanceof MyReview) {
            return null;
        }

        return [
            'id' => $mine->id,
            'rating' => $mine->rating,
            'submitted_at' => $mine->submittedAt->toIso8601ZuluString(),
        ];
    }
}
