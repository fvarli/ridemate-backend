<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Trip;

/**
 * The outcome of ending a journey, with the one distinction HTTP will need.
 *
 * `wasAlreadyEnded` separates a journey that has just ended from one that had
 * already ended the same way. Both are successes and both describe the same
 * resource; only the second wrote nothing. Mirrors `StartedTrip`.
 */
final readonly class EndedTrip
{
    public function __construct(
        public Trip $trip,
        public bool $wasAlreadyEnded,
    ) {}
}
