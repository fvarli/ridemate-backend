<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Requests\ListMySeatRequestsRequest;
use App\Http\Responses\MySeatRequestPayload;
use App\Models\SeatRequest;
use App\Reviews\MyReviewLookup;
use App\SeatRequests\ListMySeatRequests;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/me/seat-requests`
 *
 * A member's own history, newest first, including askings on journeys that
 * were later cancelled or have departed. This is the only surface that says
 * what came of a request, so nothing is filtered by what discovery would show.
 */
final class ListMySeatRequestsController
{
    public function __invoke(
        ListMySeatRequestsRequest $request,
        ListMySeatRequests $list,
        MyReviewLookup $reviews,
    ): JsonResponse {
        $caller = AuthContext::of($request)->account;

        $page = $list($caller, $request->cursor(), $request->limit());

        return new JsonResponse(MySeatRequestPayload::page(
            $page,
            // One extra query for the whole page, not one per row — the lesson
            // `my_seat_request` taught in Phase 12. Only this listing carries
            // `my_review`; the command responses that share the payload's
            // `from()` do not.
            $reviews->forMany(
                array_map(
                    static fn (object $row): SeatRequest => $row->request,
                    $page->requests,
                ),
                $caller,
            ),
        ));
    }
}
