<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Auth\AuthContext;
use App\Http\Responses\AccountPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/me`.
 *
 * The account is ALREADY RESOLVED by the time this runs. The token service
 * eager-loads `session.account` while validating the credential, and the
 * middleware puts the result on the request as an AuthContext — so this reads a
 * model that is already in memory and issues no query of its own.
 *
 * That is worth stating because the obvious alternative is worth avoiding: a
 * controller that took an account id from the context and looked it up again
 * would double the work of every authenticated request and, more importantly,
 * would be a second place where "who is this" is decided. Authentication
 * happens once, in the middleware, and everything downstream consumes it.
 *
 * The response deliberately says nothing about the session that authorised it.
 * A client already knows its own session id — it was handed one at sign-in —
 * and repeating it here would only add a field the contract does not have.
 */
final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(
            AccountPayload::from(AuthContext::of($request)->account),
        );
    }
}
