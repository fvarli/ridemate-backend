<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\SeatRequest;
use App\Routes\DiscoveredRoute;
use App\Routes\DiscoveryPage;
use Carbon\CarbonImmutable;

/**
 * A journey as a stranger may see it, and the member who published it.
 *
 * WHY THIS IS NOT RoutePayload PLUS A DRIVER
 *
 * They look almost the same and are answering different questions. RoutePayload
 * shows a member their own route, so it carries `status`, `published_at` and
 * `cancelled_at` — bookkeeping about a thing they own. A discovery result is
 * somebody else's journey seen by a stranger, and none of that is theirs to
 * read: every route here is published and upcoming by construction, so a status
 * field would be a constant, and the timestamps say when a row was written
 * rather than anything about the journey.
 *
 * Sharing one payload would mean one edit to the owner's view silently widening
 * what strangers see. Two lists of named fields cost a few lines and cannot
 * drift into each other.
 *
 * THE DRIVER IS TWO FIELDS
 *
 * A display name and the initials the server derived from it, via
 * App\Profiles\DisplayName — the Phase 11 implementation, asked rather than
 * repeated. No account id, no profile id: neither is published anywhere, and a
 * discovery result is the last place to start. No rating, verification, trip
 * count, trust score or approval rate either — the product has none of them,
 * and a plausible number beside a real name is how a fixture becomes a claim.
 */
final class DiscoveryPayload
{
    /**
     * @param  array<string, SeatRequest>  $mine  the caller's own asking per route id,
     *                                            resolved in one query for this page only
     * @return array{routes: list<array<string, mixed>>, next_cursor: string|null}
     */
    public static function page(
        DiscoveryPage $page,
        array $mine,
        ?CarbonImmutable $now = null,
    ): array {
        $routes = [];

        foreach ($page->routes as $found) {
            $routes[] = self::from($found, $mine[$found->route->id] ?? null, $now);
        }

        return [
            'routes' => $routes,
            // Null means there is no eligible route left behind this position —
            // not that this page happened to end. An empty list therefore
            // always arrives with a null cursor.
            'next_cursor' => $page->nextCursor?->encode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function from(
        DiscoveredRoute $found,
        ?SeatRequest $mine,
        ?CarbonImmutable $now,
    ): array {
        $route = $found->route;

        return [
            'id' => $route->id,
            'origin' => PlacePayload::from($route->originPlace),
            'destination' => PlacePayload::from($route->destinationPlace),
            'recurrence' => $route->recurrence->value,
            'departure_date' => $route->departure_date?->format('Y-m-d'),
            // PostgreSQL returns `08:00:00`; the contract publishes minutes,
            // and the backend refuses seconds on the way in.
            'departure_time' => substr($route->departure_time, 0, 5),
            'timezone' => $route->timezone,
            'departure_state' => $route->departureState($now)->value,
            // Offered, never available. What is left after seat requests is
            // Phase 13's to know, and this number would be a plausible answer
            // to a question nobody has implemented.
            'seats_offered' => $route->seats_offered,
            'rules' => [
                'no_smoking' => $route->rule_no_smoking,
                'music_ok' => $route->rule_music_ok,
                'no_pets' => $route->rule_no_pets,
                'quiet' => $route->rule_quiet,
            ],
            'driver' => [
                'display_name' => $found->driver->display_name,
                'initials' => $found->driver->initials(),
            ],
            // The caller's OWN asking about this journey, and nothing else
            // about anybody else's. Without it the card would offer to request
            // a seat again after the app restarts — the server already knows
            // it cannot, and a screen must not offer an action the server has
            // already ruled out. Lifetime uniqueness means zero or one, so a
            // terminal state here never becomes requestable again.
            'my_seat_request' => $mine === null ? null : [
                'id' => $mine->id,
                'status' => $mine->status->value,
            ],
        ];
    }
}
