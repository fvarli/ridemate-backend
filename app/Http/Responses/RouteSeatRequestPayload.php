<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Reviews\MyReview;
use App\SeatRequests\IncomingSeatRequest;
use App\SeatRequests\SeatRequestPage;

/**
 * What a driver sees of somebody's asking.
 *
 * The journey is addressed in the path and owned by the caller, so it is not
 * repeated on every row. The passenger is a display name and the initials
 * derived from it — the same projection discovery gives of a driver, pointed
 * the other way — and nothing else: no account id, no profile id, no phone, no
 * session metadata, and no rating, trust score or verification, none of which
 * exist.
 */
final class RouteSeatRequestPayload
{
    /**
     * ONLY THE LISTING CARRIES `my_review`.
     *
     * `from()` and `envelope()` stay as they were, because they are shared with
     * accepting and declining — see the same note on `MySeatRequestPayload`.
     *
     * @param  SeatRequestPage<IncomingSeatRequest>  $page
     * @param  array<string, MyReview>  $mine  keyed by seat request id
     * @return array{seat_requests: list<array<string, mixed>>, next_cursor: string|null}
     */
    public static function page(SeatRequestPage $page, array $mine = []): array
    {
        $requests = [];

        foreach ($page->requests as $incoming) {
            $requests[] = self::from($incoming) + [
                'my_review' => ReviewPayload::mine($mine[$incoming->request->id] ?? null),
            ];
        }

        return [
            'seat_requests' => $requests,
            'next_cursor' => $page->nextCursor?->encode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function envelope(IncomingSeatRequest $incoming): array
    {
        return ['seat_request' => self::from($incoming)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function from(IncomingSeatRequest $incoming): array
    {
        $request = $incoming->request;

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'requested_at' => $request->requested_at->toIso8601ZuluString(),
            'decided_at' => $request->decided_at?->toIso8601ZuluString(),
            'withdrawn_at' => $request->withdrawn_at?->toIso8601ZuluString(),
            'passenger' => [
                'display_name' => $incoming->passenger->display_name,
                'initials' => $incoming->passenger->initials(),
            ],
        ];
    }
}
