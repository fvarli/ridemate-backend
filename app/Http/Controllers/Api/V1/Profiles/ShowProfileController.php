<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profiles;

use App\Auth\AuthContext;
use App\Http\Responses\ProfilePayload;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v1/me/profile`.
 *
 * WHY A MISSING PROFILE IS A 404 AND NOT AN EMPTY 200
 *
 * "Authenticated but not yet named" is a real state, and the client routes on
 * it: no profile means mandatory setup. A 200 carrying nulls would make the
 * client inspect fields to work out which state it was in, and a client that
 * inspects fields eventually treats a failed decode as "no profile" — which is
 * exactly the confusion this phase must not have. A status code cannot be
 * misread that way.
 *
 * It is deliberately NOT a 204 either. There is no resource here, which is what
 * 404 means; 204 would say the resource exists and has no content.
 *
 * The account is already in memory — the token service eager-loads it while
 * validating the credential — so this reads the relation rather than looking an
 * account up a second time, the same way MeController does.
 */
final class ShowProfileController
{
    public function __invoke(Request $request): JsonResponse
    {
        $profile = AuthContext::of($request)->account->profile;

        if (! $profile instanceof Profile) {
            // The ordinary refusal, through the shared error contract. Nothing
            // here says whose profile is missing or why.
            throw new NotFoundHttpException('No profile exists for this account.');
        }

        return new JsonResponse(ProfilePayload::from($profile));
    }
}
