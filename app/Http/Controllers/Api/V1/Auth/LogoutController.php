<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\AuthContext;
use App\Auth\TokenService;
use App\Models\SessionRevocationReason;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `POST /api/v1/auth/logout`.
 *
 * Ends the session behind the presented access token — one write, and both
 * credentials die with it. The access token stops working before its own
 * expiry and the refresh token can no longer be exchanged, because every
 * validation path joins the session row.
 *
 * Other devices are untouched. Each has its own session, and signing out of
 * one is not a statement about the others.
 *
 * An already-revoked session still answers 204: the member asked to be signed
 * out and they are signed out. That is not a new success behaviour for an
 * invalid credential — the middleware has already refused those, and a revoked
 * session cannot authenticate in the first place.
 */
final class LogoutController
{
    public function __invoke(Request $request, TokenService $tokens): Response
    {
        $tokens->revoke(
            AuthContext::of($request)->session,
            SessionRevocationReason::Logout,
        );

        // 204 with a genuinely empty body, as documented.
        return response()->noContent();
    }
}
