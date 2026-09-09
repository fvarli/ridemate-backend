<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\SeatRequests\OwnSeatRequest;
use App\SeatRequests\SeatRequestPage;

/**
 * What a member sees of their own asking.
 *
 * TWO TRUTHS, NEITHER REWRITTEN
 *
 * `status` is the asking's own history — what this member asked and what answer
 * they got. `route.status` and `route.departure_state` are the journey as it
 * stands now. A request stays `accepted` beside a route that is `cancelled`,
 * and the client renders both; collapsing them into one state would claim a
 * decision nobody made.
 *
 * The route half is what discovery already publishes plus `status`, which
 * discovery has no vocabulary for because it only ever shows live journeys.
 *
 * Not here: any account or profile id, any phone number, any rating, trust
 * score, verification, cost, or remaining-seat figure. `seats_offered` keeps
 * its meaning — what the driver offered, never what is left.
 *
 * Separate from the driver's payload on purpose: one shared class would let an
 * edit made for one audience quietly widen the other's view.
 */
final class MySeatRequestPayload
{
    /**
     * @param  SeatRequestPage<OwnSeatRequest>  $page
     * @return array{seat_requests: list<array<string, mixed>>, next_cursor: string|null}
     */
    public static function page(SeatRequestPage $page): array
    {
        $requests = [];

        foreach ($page->requests as $own) {
            $requests[] = self::from($own);
        }

        return [
            'seat_requests' => $requests,
            // Null means the end of the list. An empty page does not, which is
            // why this is always present rather than omitted.
            'next_cursor' => $page->nextCursor?->encode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function envelope(OwnSeatRequest $own): array
    {
        return ['seat_request' => self::from($own)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function from(OwnSeatRequest $own): array
    {
        $request = $own->request;
        $route = $own->route;

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'requested_at' => $request->requested_at->toIso8601ZuluString(),
            'decided_at' => $request->decided_at?->toIso8601ZuluString(),
            'withdrawn_at' => $request->withdrawn_at?->toIso8601ZuluString(),
            'route' => [
                'id' => $route->id,
                'origin' => PlacePayload::from($route->originPlace),
                'destination' => PlacePayload::from($route->destinationPlace),
                'recurrence' => $route->recurrence->value,
                'departure_date' => $route->departure_date?->format('Y-m-d'),
                // PostgreSQL returns `08:00:00`; the contract publishes minutes.
                'departure_time' => substr($route->departure_time, 0, 5),
                'timezone' => $route->timezone,
                // The journey as it now stands. Computed once, by the read
                // model, against the clock that read used.
                'status' => $route->status->value,
                'departure_state' => $own->departureState->value,
                'seats_offered' => $route->seats_offered,
                'rules' => [
                    'no_smoking' => $route->rule_no_smoking,
                    'music_ok' => $route->rule_music_ok,
                    'no_pets' => $route->rule_no_pets,
                    'quiet' => $route->rule_quiet,
                ],
                'driver' => [
                    'display_name' => $own->driver->display_name,
                    'initials' => $own->driver->initials(),
                ],
            ],
        ];
    }
}
