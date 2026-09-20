<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Registration;

use App\Http\Requests\RequestRegistrationPasscodeRequest;
use App\Otp\Email\EmailDeliveryFailed;
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
 * Rate refusals surface as `429`, through the shared renderer and not caught
 * here. The destination-wide cooldown and hourly cap are spent here too: they
 * are keyed on `(channel, destination)` across every scope, so minting
 * registrations cannot multiply what one address may receive.
 *
 * AN EMAIL DELIVERY FAILURE IS STILL `202`, AND THAT IS THE SECURITY BOUNDARY
 *
 * It used to be `500`, and that was safe only while no sender could tell one
 * recipient from another. A real SMTP transport can: providers reject an
 * invalid, unroutable or suppressed recipient synchronously, at submission. If
 * that surfaced as `500` while a deliverable address got `202`, this endpoint
 * would answer "does this mailbox exist?" — and, because suppression lists are
 * built from past bounces, partly "has this address been used here before?".
 * Both are questions the whole registration surface is built to refuse.
 *
 * So once the challenge has been ISSUED, the response is fixed. `202` says one
 * thing and has always said one thing: **the request was accepted**. Not
 * delivered, not that the mailbox exists, not that anybody was verified.
 * Proof is still presenting the actual code, which nothing here hands out.
 *
 * Classifying the transport's rejection — recipient-specific versus
 * infrastructure — would rebuild the same oracle out of the provider's own
 * error codes, so it is deliberately not done. The failure is observable where
 * it belongs: `SendRegistrationPasscode` logs `otp.delivery_failed` against the
 * challenge id, under the request id every log line already carries, naming
 * neither destination, code nor provider response.
 *
 * SMS IS UNCHANGED, AND NOT BY OVERSIGHT
 *
 * `SmsDeliveryFailed` still becomes `500`. No SMS provider has been selected,
 * so that sender refuses EVERY send identically and distinguishes no recipient
 * — there is no oracle to close, and a channel the caller chose is not a fact
 * about the caller's destination. The day an SMS provider arrives, the slice
 * that introduces it owns this same normalization.
 *
 * ONE RESIDUAL, WRITTEN DOWN RATHER THAN HIDDEN
 *
 * Delivery is synchronous, so a rejected recipient may still be distinguishable
 * by RESPONSE TIME. Closing that needs delivery to move off the request, which
 * is async infrastructure this pilot does not have and will not invent for one
 * endpoint. It is a documented demo/pilot residual — see docs/architecture.md.
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

        try {
            $send($registration, $request->channel(), $request->destination());
        } catch (EmailDeliveryFailed) {
            // Swallowed HERE and nowhere deeper. The action still throws, so
            // every internal caller still learns that delivery failed and the
            // warning is still written; what is normalized is the one thing a
            // stranger can observe. Caught after issuance by construction:
            // this exception cannot be raised before the challenge commits.
        }

        return new JsonResponse(['status' => 'accepted'], JsonResponse::HTTP_ACCEPTED);
    }
}
