<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Auth\IssuedTokenPair;
use Illuminate\Http\JsonResponse;

/**
 * The one place the token-pair body is shaped.
 *
 * Two endpoints return it, and the contract says they return the SAME thing.
 * Building the array twice is how that stops being true — one of them gains a
 * field, or loses one, and the difference is a contract change nobody reviewed.
 *
 * Note what is not here: no token id, no generation, no account id, no refresh
 * expiry. Those exist on the row and are the server's bookkeeping. Publishing
 * any of them would turn an implementation detail into a promise and hand an
 * attacker a map of the token chain.
 */
final class TokenPairResponse
{
    public static function from(IssuedTokenPair $pair): JsonResponse
    {
        return new JsonResponse([
            'access_token' => $pair->accessToken,
            'refresh_token' => $pair->refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $pair->expiresIn,
            'session_id' => $pair->sessionId,
        ], JsonResponse::HTTP_OK);
    }
}
