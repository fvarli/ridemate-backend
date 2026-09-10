<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Trip;

/**
 * The outcome of starting, with the one distinction HTTP will need.
 *
 * `wasAlreadyStarted` separates a trip that has just begun from one that was
 * already under way. Both are successes and both describe the same resource;
 * only the second wrote nothing.
 */
final readonly class StartedTrip
{
    public function __construct(
        public Trip $trip,
        public bool $wasAlreadyStarted,
    ) {}
}
