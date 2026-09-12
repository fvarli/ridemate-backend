<?php

declare(strict_types=1);

namespace App\Reviews;

use RuntimeException;

/**
 * The review domain refusing a submission, and why.
 *
 * Carries a `RefusalReason` rather than an HTTP status: the domain knows what
 * it refused, and the controller knows what that is worth over HTTP. Same split
 * as `App\Routes\RoutePublicationRefused`, `App\SeatRequests\SeatRequestRefused`
 * and `App\Trips\TripRefused`.
 *
 * NOTHING IS ATTACHED TO IT
 *
 * Unlike a seat-request refusal, which carries the row whose state caused it,
 * these say the state and stop. `already_reviewed` in particular must not carry
 * the existing review: the caller wrote it, so they can read it from their own
 * projection — and the refusal's job is to say the relationship is spoken for,
 * not to hand back a row the caller may have meant to replace. There is no
 * replacing in Phase 15.
 */
final class ReviewRefused extends RuntimeException
{
    private function __construct(
        public readonly RefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function seatRequestNotAccepted(): self
    {
        return new self(
            RefusalReason::SeatRequestNotAccepted,
            'That seat was never agreed, so there is no shared journey to rate.',
        );
    }

    public static function tripNotCompleted(): self
    {
        return new self(
            RefusalReason::TripNotCompleted,
            'That journey has not been reported as made.',
        );
    }

    public static function reviewWindowClosed(): self
    {
        return new self(
            RefusalReason::ReviewWindowClosed,
            'The review window for that journey has closed.',
        );
    }

    public static function alreadyReviewed(): self
    {
        return new self(
            RefusalReason::AlreadyReviewed,
            'You have already reviewed that journey.',
        );
    }

    public static function idAlreadyUsed(): self
    {
        return new self(
            RefusalReason::IdAlreadyUsed,
            'That review id belongs to a different review.',
        );
    }
}
