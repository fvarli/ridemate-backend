<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Registration;

use App\Http\Responses\StartedRegistrationResponse;
use App\Registration\RegistrationService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/registrations`.
 *
 * Opens a pre-account registration and hands back the credential that advances
 * it. **No account exists after this, and no session does.** There is no code
 * path from `RegistrationService` to `TokenService`, and the credential it
 * mints is refused by every authenticated route by construction.
 *
 * TAKES NO BODY AT ALL, AND THAT IS THE DOMAIN'S DECISION RATHER THAN A SHORTCUT
 *
 * A registration that had to be told an address up front would fix the order
 * the two channels are proven in, and nothing about the domain requires one —
 * so `RegistrationService::start()` takes no arguments and this takes no body.
 * A destination is named at the moment a code is sent to it, by
 * `POST /api/v1/registrations/otp`, which is what stops a registration coming
 * to name an address nobody asked for a code at.
 *
 * It follows that this endpoint reads nothing a caller supplied, consults
 * `accounts` for nothing, and cannot vary its answer. There is no branch here
 * that could tell anyone anything.
 *
 * `201` because a resource was created and the body is its credential. No
 * `Location` header: there is no addressable registration resource, and
 * inventing one would publish the row id as an address.
 */
final class StartRegistrationController
{
    public function __invoke(RegistrationService $registrations): JsonResponse
    {
        return StartedRegistrationResponse::from($registrations->start());
    }
}
