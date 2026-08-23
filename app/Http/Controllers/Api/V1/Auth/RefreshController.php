<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\TokenService;
use App\Http\Requests\RefreshTokenRequest;
use App\Http\Responses\TokenPairResponse;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/auth/refresh`.
 *
 * The route carries NO authentication middleware, and that is deliberate. The
 * refresh credential arrives in the body: it authorises one action, and
 * accepting it as a bearer token would teach clients to attach it to ordinary
 * requests, spreading a long-lived credential through logs and proxies that
 * were only ever meant to see a short-lived one.
 *
 * Rotation, reuse detection and family revocation all happen inside the token
 * service, under a row lock. None of it is reachable from here, which is what
 * stops a controller from ever becoming a second, subtly different
 * implementation of the most security-sensitive code in the system.
 */
final class RefreshController
{
    public function __invoke(RefreshTokenRequest $request, TokenService $tokens): JsonResponse
    {
        return TokenPairResponse::from($tokens->rotate($request->refreshToken()));
    }
}
