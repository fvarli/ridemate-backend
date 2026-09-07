<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profiles;

use App\Auth\AuthContext;
use App\Http\Requests\SaveProfileRequest;
use App\Http\Responses\ProfilePayload;
use App\Profiles\SavedProfile;
use App\Profiles\SaveProfile;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `PUT /api/v1/me/profile`.
 *
 * A TARGET-STATE WRITE, WHICH IS WHY PUT AND WHY NO IDEMPOTENCY KEY
 *
 * The body names what the profile should say, not a change to apply, so sending
 * it twice leaves the same profile with the same name. That is tier 2 of
 * docs/api-conventions.md — idempotent by the shape of the operation — and it
 * is the same reasoning that lets route cancellation go without a key.
 *
 * 201 the first time and 200 afterwards, because the two are genuinely
 * different events and a client that has just created its profile leaves setup
 * while one that renamed does not. Only App\Profiles\SaveProfile is in a
 * position to know which happened: asking afterwards would be asking a question
 * whose answer had already changed.
 *
 * Everything else lives in the action, deliberately — the create race, the
 * locking and the convergence on one profile per account are domain concerns
 * and must read the same way whether they are reached from HTTP or from a
 * console command.
 */
final class SaveProfileController
{
    public function __invoke(SaveProfileRequest $request, SaveProfile $save): JsonResponse
    {
        $saved = $save(
            // Ownership from the credential, never from the payload.
            AuthContext::of($request)->account,
            $request->displayName(),
        );

        return new JsonResponse(
            ProfilePayload::from($saved->profile),
            $this->statusFor($saved),
        );
    }

    private function statusFor(SavedProfile $saved): int
    {
        return $saved->created
            ? Response::HTTP_CREATED
            : Response::HTTP_OK;
    }
}
