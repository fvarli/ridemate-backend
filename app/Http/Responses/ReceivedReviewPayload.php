<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Reviews\ReceivedReview;
use App\Reviews\ReviewPage;

/**
 * A released review, as the member it is about reads it.
 *
 * ONE MEMBER'S SELF-DECLARED RATING, AND NOTHING MORE
 *
 * Not evidence that anybody boarded, was picked up, or travelled. Every fact
 * RideMate holds about a journey is the driver's own declaration, and this
 * publishes a rating somebody chose to give — the journey beside it is
 * attribution, not proof.
 *
 * THE JOURNEY IS FOUR STRINGS AND NO IDENTIFIER
 *
 * Two labels, a date and a wall clock. A member holding several ratings from
 * the same person needs to know which shared journey each refers to; they do
 * not need a route id, a trip id or a seat request id, and publishing one would
 * be a capability nobody asked for on a screen that addresses nothing.
 *
 * Places are labels rather than the `Place` object every other surface uses,
 * deliberately: that object carries an id which addresses a place in discovery,
 * and this screen has no use for one. The absence of a type, not a duplicate.
 *
 * `departure_date` is never null here. A recurring route cannot have a trip, so
 * every reviewable journey is one-off and dated — the nullability that exists
 * on `Route` is unreachable on this surface.
 *
 * NO AGGREGATE
 *
 * No count, no average, no distribution, not even for the member the reviews
 * are about. A number is the first half of a reputation, and the second half
 * arrives without anybody deciding to build it.
 */
final class ReceivedReviewPayload
{
    /**
     * @return array{reviews: list<array<string, mixed>>, next_cursor: string|null}
     */
    public static function page(ReviewPage $page): array
    {
        $reviews = [];

        foreach ($page->reviews as $received) {
            $reviews[] = self::from($received);
        }

        return [
            'reviews' => $reviews,
            // Null means the end of the list. An empty page does not, which is
            // why this is always present rather than omitted.
            'next_cursor' => $page->nextCursor?->encode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function from(ReceivedReview $received): array
    {
        $route = $received->route;

        return [
            'id' => $received->review->id,
            'rating' => $received->review->rating,
            'submitted_at' => $received->review->created_at->toIso8601ZuluString(),
            'reviewer' => [
                // The server's own, both of them. Turkish casing makes initials
                // a rule rather than a formatting detail, so deriving them on a
                // client would be authoring a rule the backend owns.
                'display_name' => $received->reviewer->display_name,
                'initials' => $received->reviewer->initials(),
                'role' => $received->role()->value,
            ],
            'journey' => [
                'origin' => $route->originPlace->label,
                'destination' => $route->destinationPlace->label,
                // Not null on this surface: see the note above.
                'departure_date' => $route->departure_date?->format('Y-m-d'),
                // `HH:MM`, the one time format this contract has. A review is
                // not the place to introduce a second.
                'departure_time' => substr($route->departure_time, 0, 5),
            ],
        ];
    }
}
