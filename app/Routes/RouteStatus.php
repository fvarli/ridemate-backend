<?php

declare(strict_types=1);

namespace App\Routes;

/**
 * Where a published route stands.
 *
 * Two states, because Phase 10 has behaviour for exactly two. `expired` is
 * deliberately absent: a one-off journey whose departure has passed is not a
 * stored state, it is a comparison against the clock. Persisting it would need
 * something to keep it true — a scheduled sweep — and a route would then be
 * "not expired yet" for as long as that job was late, which is a worse lie than
 * having no column at all.
 *
 * `requested`, `accepted`, `started` and `completed` are absent for the same
 * reason: nothing in this phase produces or consumes them.
 */
enum RouteStatus: string
{
    case Published = 'published';
    case Cancelled = 'cancelled';
}
