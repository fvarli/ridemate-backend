<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Journeys\Journey;
use App\Journeys\JourneyPage;
use App\Trips\TripLifecycle;

/**
 * One dated journey as its own driver sees it.
 *
 * WHY THIS IS NOT `MyRoute` WITH A DATE
 *
 * `MyRoute` is a PLAN: it carries the recurrence, the seats, the rules and when
 * the row was published, because that is what an owner edits and cancels. This
 * is one OCCURRENCE of that plan, and a driver reading it is asking a different
 * question — which journey, where, when, and has it been made. Recurrence and
 * seats would be repeated on every date of a weekday plan and say nothing about
 * the day; `published_at` and `departure_state` describe the plan rather than
 * the journey.
 *
 * NOBODY ELSE IS IN IT
 *
 * No driver identity — the caller is the driver. No passenger names, no
 * passenger count, no accepted-seat figure: who is aboard is not a column and
 * no phase has designed it, and a number here would be answered from seat
 * requests, which are a different question with a surface of their own.
 *
 * THE LIFECYCLE IS ALWAYS PRESENT
 *
 * Never null on this surface, unlike `MyRoute.trip`. A journey is a concrete
 * date, so the question always has an answer, and no stored row means
 * `not_started` — `App\Trips\TripLifecycle` turning absence into a state, as it
 * does everywhere else.
 */
final class JourneyPayload
{
    /**
     * @return array{journeys: list<array<string, mixed>>, next_cursor: string|null}
     */
    public static function page(JourneyPage $page): array
    {
        $journeys = [];

        foreach ($page->journeys as $journey) {
            $journeys[] = self::from($journey);
        }

        return [
            'journeys' => $journeys,
            // Null means there is no eligible journey left behind this
            // position, not that this page happened to end. An empty list
            // therefore always arrives with a null cursor.
            'next_cursor' => $page->nextCursor?->encode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function from(Journey $journey): array
    {
        $route = $journey->route;

        return [
            // The journey's identity, and the only way to address it again.
            // There is no journey id: a date and a route are the identity, and
            // minting a second name for them would let the two disagree.
            'route_id' => $route->id,
            'service_date' => $journey->serviceDate->format('Y-m-d'),
            'origin' => PlacePayload::from($route->originPlace),
            'destination' => PlacePayload::from($route->destinationPlace),
            // PostgreSQL returns `08:00:00`; the contract publishes minutes.
            'departure_time' => substr($route->departure_time, 0, 5),
            'timezone' => $route->timezone,
            // The PLAN's status, said plainly rather than folded into the trip:
            // a cancelled plan can still hold a journey that is under way, and
            // a driver ending one needs to see both facts rather than one
            // derived from the other.
            'route_status' => $route->status->value,
            'trip' => TripPayload::from(TripLifecycle::of($journey->trip)),
        ];
    }
}
