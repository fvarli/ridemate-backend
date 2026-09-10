<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * What a route can say about whether its journey was made.
 *
 * NOT A ROW SHAPE
 *
 * Deliberately one level away from the `trips` table. The table holds three
 * states because a row exists only once a journey has started; a route has
 * four things it can say, and the fourth — `not_started` — is exactly what the
 * absence of a row means. Turning that absence into a state here is what lets
 * every projection speak about every route without the database having to store
 * a row for journeys nobody has begun.
 *
 * The timestamps are the persisted ones, never derived: `started_at` is when a
 * driver pressed Start and the server accepted it, not when the route was due
 * to leave. Nothing here computes anything from the clock.
 */
final readonly class TripLifecycle
{
    private function __construct(
        public TripState $state,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        public ?CarbonImmutable $abortedAt,
    ) {}

    /**
     * The lifecycle of a route, given the trip it has or has not got.
     *
     * Null is the ordinary case rather than missing data: most routes have
     * never been started, and saying so is the whole reason this type exists.
     */
    public static function of(?Trip $trip): self
    {
        if (! $trip instanceof Trip) {
            return new self(TripState::NotStarted, null, null, null);
        }

        return new self(
            // One-to-one by construction: every stored status names a state,
            // and `not_started` is the only state no status can produce.
            match ($trip->status) {
                TripStatus::InProgress => TripState::InProgress,
                TripStatus::Completed => TripState::Completed,
                TripStatus::Aborted => TripState::Aborted,
            },
            $trip->started_at,
            $trip->completed_at,
            $trip->aborted_at,
        );
    }

    /** Whether a journey is under way right now. */
    public function isInProgress(): bool
    {
        return $this->state === TripState::InProgress;
    }

    /** Whether anything has been recorded about this journey being made. */
    public function hasStarted(): bool
    {
        return $this->state !== TripState::NotStarted;
    }
}
