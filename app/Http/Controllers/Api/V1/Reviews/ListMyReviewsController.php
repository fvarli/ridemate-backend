<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reviews;

use App\Auth\AuthContext;
use App\Http\Requests\ListMyReviewsRequest;
use App\Http\Responses\ReceivedReviewPayload;
use App\Reviews\ListMyReviews;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/me/reviews`
 *
 * What other members have said about this one, newest first, and only the
 * reviews they are entitled to read — a review stays hidden until the other
 * side writes one too or the window closes.
 *
 * The release rule is not here. It is a predicate inside `ListMyReviews`, so a
 * hidden review is never a candidate: it cannot take a page slot, cannot
 * shorten a page, and cannot be what a cursor names. Filtering here would undo
 * all three.
 *
 * There is no count and no average. Not even for the member the reviews are
 * about: Phase 15 publishes no reputation.
 */
final class ListMyReviewsController
{
    public function __invoke(
        ListMyReviewsRequest $request,
        ListMyReviews $list,
    ): JsonResponse {
        $page = $list(
            AuthContext::of($request)->account,
            $request->cursor(),
            $request->limit(),
        );

        return new JsonResponse(ReceivedReviewPayload::page($page));
    }
}
