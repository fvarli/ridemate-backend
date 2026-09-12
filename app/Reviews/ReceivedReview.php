<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Profile;
use App\Models\Review;
use App\Models\Route;
use Carbon\CarbonImmutable;

/**
 * A released review, as the member it is about may read it.
 *
 * WHAT IT IS, AND WHAT IT IS NOT
 *
 * One member's self-declared rating about one completed relationship. The
 * journey travels with it for attribution only — a member holding several
 * ratings from the same person needs to know which shared journey each refers
 * to. It claims nothing about attendance: neither the route nor the rating is
 * evidence that anybody boarded.
 *
 * The reviewer is a `Profile` and only a `Profile`: a display name and the
 * initials the server derived from it. There is no `Account` here, so no phone
 * number, credential or id can reach a projection by accident.
 *
 * The route is carried whole because the payload layer needs its places and its
 * departure, and it is the same `Route` every other read surface uses. Which of
 * its fields reach the wire is B4's decision, and the contract's answer is:
 * two labels, a date and a time, and no identifier of any kind.
 */
final readonly class ReceivedReview
{
    public function __construct(
        public Review $review,
        public Profile $reviewer,
        public Route $route,
    ) {}

    /** Which side wrote it, relative to the relationship it is about. */
    public function role(): ReviewerRole
    {
        return $this->review->reviewer_role;
    }

    /**
     * The date of the journey this review is about.
     *
     * Read from the ASKING rather than the route. A route is a plan and may run
     * on many dates; the seat request names the one this member was accepted
     * onto, which is the journey being rated. It is also why this is never
     * null: a plan's own `departure_date` is absent for a recurring route,
     * while an asking always carries the day it was for.
     */
    public function serviceDate(): CarbonImmutable
    {
        return $this->review->seatRequest->service_date;
    }
}
