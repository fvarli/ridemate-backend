<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SeatRequests;

use App\Auth\AuthContext;
use App\Http\Requests\ListRouteSeatRequestsRequest;
use App\Http\Responses\RouteSeatRequestPayload;
use App\Models\SeatRequest;
use App\Reviews\MyReviewLookup;
use App\SeatRequests\ListRouteSeatRequests;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/routes/{routeId}/seat-requests`
 *
 * Who has asked for a seat on a journey the caller published. The route is
 * fetched scoped to the caller, so a driver asking about somebody else's
 * journey gets the same answer as one asking about a journey that does not
 * exist.
 */
final class ListRouteSeatRequestsController
{
    public function __invoke(
        ListRouteSeatRequestsRequest $request,
        string $routeId,
        ListRouteSeatRequests $list,
        MyReviewLookup $reviews,
    ): JsonResponse {
        $caller = AuthContext::of($request)->account;

        $page = $list($caller, $routeId, $request->cursor(), $request->limit());

        return new JsonResponse(RouteSeatRequestPayload::page(
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
