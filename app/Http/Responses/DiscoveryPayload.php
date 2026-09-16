<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\SeatRequest;
use App\Routes\DiscoveredRoute;
use App\Routes\DiscoveryPage;
use App\SeatRequests\RequestableServiceDates;
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
     * @param  array<string, list<SeatRequest>>  $mine  the caller's own askings per route
     *                                                  id, resolved in one query for this
     *                                                  page only
     * @return array{routes: list<array<string, mixed>>, next_cursor: string|null}
     */
    public static function page(
        DiscoveryPage $page,
        array $mine,
        ?CarbonImmutable $now = null,
    ): array {
        $routes = [];

        foreach ($page->routes as $found) {
            $routes[] = self::from($found, $mine[$found->route->id] ?? [], $now);
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
     * @param  list<SeatRequest>  $mine
     * @return array<string, mixed>
     */
    private static function from(
        DiscoveredRoute $found,
        array $mine,
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
            // WHICH OF THIS PLAN'S DAYS MAY BE ASKED ABOUT, RIGHT NOW.
            //
            // Server-derived, because it is route-local temporal truth: what
            // "today" is where this route runs, when today's departure passes,
            // and how far ahead the horizon reaches are all read in the route's
            // own timezone. A client working them out would need an IANA
            // database shipped in its binary and a second implementation of
            // rules that live in `RequestableJourney` — so it is answered here,
            // once, by asking that class about each candidate day.
            //
            // NOT FILTERED BY WHO IS ASKING. A day the caller has already asked
            // about stays in this list; `my_seat_requests` below is what says
            // they have spent it. Two truths, published separately, because a
            // client needs both: the days the route offers, and the days this
            // member has used. Subtracting them here would answer neither.
            //
            // NOT A PROMISE OF A SEAT. It says the date rules are satisfied. It
            // says nothing about capacity, about whose route this is, or about
            // an asking that already exists — every one of those is refused at
            // command time, by name, and none of them has a date in it.
            'requestable_service_dates' => array_map(
                static fn (CarbonImmutable $day): string => $day->format('Y-m-d'),
                RequestableServiceDates::of($route, $now),
            ),
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
            // The caller's OWN askings about this route's still-offerable
            // journeys, and nothing about anybody else's. Without them the card
            // would offer to request a seat again after the app restarts — the
            // server already knows it cannot, and a screen must not offer an
            // action the server has already ruled out.
            //
            // A LIST, BECAUSE A PLAN HAS DAYS. One entry per dated journey the
            // caller has asked about, earliest first, and empty when they have
            // asked about none. A one-off route can carry at most one; nothing
            // reading this may collapse it back to a single value, because the
            // day is what says which journey a status belongs to.
            'my_seat_requests' => array_map(
                static fn (SeatRequest $request): array => [
                    'service_date' => $request->service_date->format('Y-m-d'),
                    'id' => $request->id,
                    'status' => $request->status->value,
                ],
                $mine,
            ),
        ];
    }
}
