<?php

declare(strict_types=1);

namespace App\Routes;

/**
 * How often a published journey happens.
 *
 * Two cases, because the approved screen offers exactly two: a single dated
 * journey, or the same weekday commute repeating. There is no RRULE, no day
 * picker, no exception list and no end date, because none of that is designed
 * and each would be a scheduling rule invented here rather than chosen by a
 * driver.
 *
 * The distinction is load-bearing rather than cosmetic. A `once` route has a
 * departure that can be in the past; a `weekdays` route never does, because it
 * has no single departure to have passed. Every temporal rule in this domain
 * follows from that one difference.
 */
enum Recurrence: string
{
    /** A single journey on a stated date. */
    case Once = 'once';

    /** The same departure, every weekday, until cancelled. */
    case Weekdays = 'weekdays';

    /**
     * Whether this recurrence carries a calendar date.
     *
     * Exactly one does, and the database enforces the same rule with a check
     * constraint — a `weekdays` route with a date would be claiming a
     * specificity it does not have.
     */
    public function requiresDepartureDate(): bool
    {
        return $this === self::Once;
    }
}
