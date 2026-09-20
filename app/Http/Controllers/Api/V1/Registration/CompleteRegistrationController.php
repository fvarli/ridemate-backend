<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Registration;

use App\Http\Requests\CompleteRegistrationRequest;
use App\Http\Responses\TokenPairResponse;
use App\Registration\CompleteRegistration;
use App\Registration\RegistrationService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/registrations/complete`.
 *
 * Turns a registration that has proven both possessions into exactly one
 * account, and returns the initial normal session opened on it.
 *
 * THE BODY IS THE ORDINARY TOKEN PAIR, AND ONLY THAT
 *
 * `TokenPairResponse` — the same shaper `POST /api/v1/auth/otp/verify` and
 * `POST /api/v1/auth/refresh` use, so the three cannot drift into describing
 * three different things. The account is NOT published here: `GET /api/v1/me`
 * is the endpoint that publishes an account, it is authenticated by the token
 * this hands back, and a second projection of the same row would be a second
 * decision about what an account may say. Nothing registration-shaped is in the
 * body either — no registration id, no proof timestamps, no completion time.
 * What a client holds afterwards is a session, indistinguishable from one
 * obtained by signing in, which is exactly what it is.
 *
 * ONCE, AND NEVER AGAIN
 *
 * Completion sets `completed_at`, so the credential stops resolving the moment
 * the transaction commits and `resolve()` answers the same null it gives an
 * unknown one. A replay is therefore a `401`, not a second account and not a
 * fresh session. A client that loses this response signs in through the normal
 * flow — the same answer Phase 9 gives for a lost refresh response, and for the
 * same reason: a credential that can mint sessions after it has served its
 * purpose is an unlimited session factory held by whoever kept a copy.
 *
 * `200` rather than `201`. An account was created, but the body is a
 * credential rather than a representation of it, there is no `Location` to give
 * and no account resource to address — and a member cannot tell this response
 * from the one the sign-in path returns, which is the truth about what they now
 * hold.
 *
 * THE REFUSALS, AND WHAT THEY MAY SAY
 *
 * Both are the shared renderer's, not this controller's. A registration that
 * has not proven both channels is a `409` naming `not_fully_proven`, which is a
 * fact about the caller's own registration. A collision with an existing
 * account is a `409` naming `account_already_exists` — ONE string, although the
 * domain knows whether the address or the number was taken, because a caller
 * that could tell them apart could aim a registration at an identifier pair and
 * read back which half was already a member. The registration stays open and
 * uncompleted, and nothing is adopted, merged or overwritten.
 */
final class CompleteRegistrationController
{
    public function __invoke(
        CompleteRegistrationRequest $request,
        RegistrationService $registrations,
        CompleteRegistration $complete,
    ): JsonResponse {
        $registration = $registrations->resolve($request->credential())
            ?? throw new AuthenticationException('The registration credential is not valid.');

        return TokenPairResponse::from(
            $complete($registration, $request->device())->tokens,
        );
    }
}
