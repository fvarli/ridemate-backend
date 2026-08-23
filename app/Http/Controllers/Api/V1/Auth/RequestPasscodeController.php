<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\RequestPasscodeRequest;
use App\Otp\SendPasscode;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/auth/otp`.
 *
 * THE SHORTEST CONTROLLER IN THE APPLICATION, ON PURPOSE
 *
 * Every rule that matters — the resend cooldown, the hourly cap, invalidating
 * predecessors, committing before dispatch — lives in the domain. Not because
 * layering is tidy, but because this endpoint's security property is that it
 * has no branches. A controller that inspected anything about the number would
 * be a place where behaviour could start to differ, and the difference is the
 * whole thing being defended against.
 *
 * There is nothing here that consults `accounts`, and nothing downstream does
 * either. The response is the same for a member, a stranger and a number that
 * has never existed.
 */
final class RequestPasscodeController
{
    public function __invoke(RequestPasscodeRequest $request, SendPasscode $send): JsonResponse
    {
        // Rate refusals surface as 429 and delivery failures as 500, both
        // through the shared renderer. Neither is caught here: a controller
        // that translated them would be a second definition of the contract.
        $send($request->phoneE164());

        return new JsonResponse(['status' => 'accepted'], JsonResponse::HTTP_ACCEPTED);
    }
}
