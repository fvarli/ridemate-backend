<?php

declare(strict_types=1);

namespace App\Journeys;

/**
 * A page of the driver's dated journeys, and where the next one starts.
 *
 * `nextCursor` is present only when another eligible journey was actually found
 * beyond this page. **Null means genuinely exhausted**, never "this candidate
 * window ended": journeys the domain steps over — a weekday plan read on a
 * Saturday — never occupy a slot and never stand in for a row that exists
 * behind them.
 */
final readonly class JourneyPage
{
    /**
     * @param  list<Journey>  $journeys
     */
    public function __construct(
        public array $journeys,
        public ?JourneyCursor $nextCursor,
    ) {}
}
