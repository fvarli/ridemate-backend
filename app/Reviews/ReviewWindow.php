<?php

declare(strict_types=1);

namespace App\Reviews;

use Carbon\CarbonImmutable;

/**
 * How long a completed journey stays reviewable, and when its reviews release.
 *
 * ONE ANCHOR, TWO DEADLINES
 *
 * Both are measured from `trips.completed_at` — the moment the driver reported
 * the journey as made — and never from a first submission. Anchoring the
 * release to whoever wrote first would let one party set the other's deadline
 * by choosing when to submit, and would make the pair's timing depend on the
 * order it arrived in.
 *
 * NOTHING IS STORED, AND NOTHING IS SCHEDULED
 *
 * Both questions are answered from timestamps the rows already carry, at the
 * moment somebody asks. A `release_at` column would be a derived fact stored a
 * second time, and a job to flip it would be a scheduler this phase does not
 * need: a review nobody reads does not need to have been released on time, only
 * to be released by the time it is read.
 */
final class ReviewWindow
{
    private function __construct() {}

    /**
     * Fourteen days.
     *
     * Long enough that a member who travels on Friday and opens the app the
     * following weekend can still write something; short enough that a rating
     * still refers to a journey either party remembers. It is a product choice
     * rather than a derived number, which is why it is written once, here.
     */
    public const DAYS = 14;

    /** When submission closes, and when an unmatched review releases anyway. */
    public static function closesAt(CarbonImmutable $completedAt): CarbonImmutable
    {
        return $completedAt->addDays(self::DAYS);
    }

    /** Whether a review may still be submitted for a journey completed then. */
    public static function isOpen(CarbonImmutable $completedAt, CarbonImmutable $now): bool
    {
        return $now->lessThan(self::closesAt($completedAt));
    }

    /**
     * Whether the window has closed, which releases whatever was written.
     *
     * The exact complement of [isOpen], stated separately because the two
     * questions read differently at their call sites and a reader should not
     * have to negate one in their head to answer the other.
     */
    public static function hasClosed(CarbonImmutable $completedAt, CarbonImmutable $now): bool
    {
        return ! self::isOpen($completedAt, $now);
    }
}
