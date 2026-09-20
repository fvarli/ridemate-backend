<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Registration;

use App\Http\Requests\RequestRegistrationPasscodeRequest;
use App\Registration\RegistrationService;
use App\Registration\SendRegistrationPasscode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/registrations/otp`.
 *
 * Binds a destination to this registration's channel, then sends a passcode to
 * it. The two happen in that order and in one action, because a challenge
 * issued for a destination the registration does not name could never be
 * verified against it.
 *
 * THE RESPONSE IS THE SIGN-IN PATH'S, FOR THE SIGN-IN PATH'S REASON
 *
 * `202` with `{"status": "accepted"}` and nothing else. No challenge id, no
 * expiry, no resend timestamp, no hint about previous requests — anything
 * varying by destination would leak what the fixed body exists to hide.
 * Nothing here consults `accounts`, and nothing downstream does either: a
 * registration cannot be used to ask whether an address or a number is already
 * a member.
 *
 * WHAT THE FAILURES SAY
 *
 * A credential that does not resolve — malformed, unknown, wrong secret,
 * expired, already completed — is one `401`, because `resolve()` cannot tell
 * them apart and neither may this. A channel already bound to a different
 * destination is a `409` naming `channel_already_bound`, which is a fact about
 * the caller's own registration and names neither destination.
 *
 * Rate refusals surface as `429` and delivery failures as `500`, both through
 * the shared renderer and neither caught here — exactly as on
 * `POST /api/v1/auth/otp`. The destination-wide cooldown and hourly cap are
 * spent here too: they are keyed on `(channel, destination)` across every
 * scope, so minting registrations cannot multiply what one address may receive.
 */
final class RequestRegistrationPasscodeController
{
    public function __invoke(
        RequestRegistrationPasscodeRequest $request,
        RegistrationService $registrations,
        SendRegistrationPasscode $send,
    ): JsonResponse {
        $registration = $registrations->resolve($request->credential())
            // Fixed text, containing nothing the caller supplied, and identical
            // for every reason the credential did not resolve.
            ?? throw new AuthenticationException('The registration credential is not valid.');

        $send($registration, $request->channel(), $request->destination());

        return new JsonResponse(['status' => 'accepted'], JsonResponse::HTTP_ACCEPTED);
    }
}
