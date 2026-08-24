<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Route;
use Carbon\CarbonImmutable;

/**
 * A published journey, as the contract describes it.
 *
 * WHAT IS NOT HERE, AND WHY THAT MATTERS MORE
 *
 * No driver name, no rating, no verification badge, no trip count, no trust
 * score, no vehicle, no cost. Not trimmed for brevity — RideMate does not have
 * any of them, and a plausible number sitting beside real departure times is
 * how a screen full of fixtures starts looking like server truth.
 *
 * `account_id` is absent too. The only member who can read this is the one who
 * published it, so repeating their id would add a field carrying no
 * information and one more thing to keep out of a log.
 *
 * `departure_state` is DERIVED at the moment of asking. It is not a column and
 * there is nothing to keep it fresh, because the clock does that. The server
 * computes it because the rule lives in the route's own timezone, and a client
 * re-deriving it would eventually disagree about whether a journey can still be
 * cancelled.
 */
final class RoutePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Route $route, ?CarbonImmutable $now = null): array
    {
        return [
            'id' => $route->id,
            'origin' => PlacePayload::from($route->originPlace),
            'destination' => PlacePayload::from($route->destinationPlace),
            'recurrence' => $route->recurrence->value,
            'departure_date' => $route->departure_date?->format('Y-m-d'),
            // PostgreSQL returns `08:00:00`; the contract publishes minutes,
            // and the backend refuses seconds on the way in. It must not emit
            // what it would not accept.
            'departure_time' => substr($route->departure_time, 0, 5),
            'timezone' => $route->timezone,
            'departure_state' => $route->departureState($now)->value,
            'seats_offered' => $route->seats_offered,
            'rules' => [
                'no_smoking' => $route->rule_no_smoking,
                'music_ok' => $route->rule_music_ok,
                'no_pets' => $route->rule_no_pets,
                'quiet' => $route->rule_quiet,
            ],
            'status' => $route->status->value,
            'published_at' => $route->published_at->toAtomString(),
            'cancelled_at' => $route->cancelled_at?->toAtomString(),
        ];
    }

    /**
     * @return array{route: array<string, mixed>}
     */
    public static function envelope(Route $route, ?CarbonImmutable $now = null): array
    {
        return ['route' => self::from($route, $now)];
    }
}
