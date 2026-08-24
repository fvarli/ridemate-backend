<?php

declare(strict_types=1);

namespace App\Routes;

/**
 * Whether a route's departure is still ahead of the member.
 *
 * DERIVED, NEVER STORED
 *
 * This is a reading of the clock, taken when someone asks. It exists as a type
 * because the client must not compute it: the rule lives in a timezone the
 * server owns, and a client re-deriving it would eventually disagree about
 * whether a journey may still be cancelled.
 *
 * A recurring route is always upcoming while it is published — it has no single
 * departure that could be behind us.
 */
enum DepartureState: string
{
    case Upcoming = 'upcoming';
    case Past = 'past';
}
