<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reviews;

use App\Auth\AuthContext;
use App\Http\Requests\SubmitReviewRequest;
use App\Http\Responses\ReviewPayload;
use App\Reviews\SubmitReview;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/seat-requests/{requestId}/review`
 *
 * `201` for a review that has just been written, `200` for a retry of one that
 * already landed — the same distinction publication and asking for a seat draw,
 * for the same reason: the client's id is the idempotency key, so a retry after
 * a lost response is the same review arriving twice rather than a second one.
 *
 * This controller chooses nothing but that code. Which side the caller is,
 * whether the relationship may be rated at all, and what a refusal is called
 * are `SubmitReview`'s, and a refusal is rendered by `ExceptionRenderer`. A
 * caller who is party to neither side raises `ModelNotFoundException` there and
 * answers 404, the same as a relationship that does not exist — nobody can
 * learn which ids are real by trying to review them.
 */
final class SubmitReviewController
{
    public function __invoke(
        SubmitReviewRequest $request,
        string $requestId,
        SubmitReview $submit,
    ): JsonResponse {
        $result = $submit(
            AuthContext::of($request)->account,
            $request->reviewId(),
            $requestId,
            $request->rating(),
        );

        return new JsonResponse(
            ReviewPayload::envelope($result->review),
            $result->wasAlreadySubmitted ? 200 : 201,
        );
    }
}
