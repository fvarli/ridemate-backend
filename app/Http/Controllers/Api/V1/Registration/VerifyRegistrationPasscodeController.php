<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Registration;

use App\Http\Requests\VerifyRegistrationPasscodeRequest;
use App\Registration\RegistrationService;
use App\Registration\VerifyRegistrationPasscode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Response;

/**
 * `POST /api/v1/registrations/otp/verify`.
 *
 * Records that this registration proved possession of the destination it named
 * on a channel. **It creates no account, opens no session and issues no
 * token** — acting on two proofs is `POST /api/v1/registrations/complete`, and
 * it is a separate transaction.
 *
 * NO DESTINATION CROSSES THIS BOUNDARY
 *
 * The request has nowhere to put one and the action takes none: the destination
 * is read from the locked registration row, which is the same row the proof is
 * written to. Attaching a code earned on an address you control to a
 * registration naming somebody else's is not something a check has to catch.
 *
 * `204`, WITH NOTHING IN IT
 *
 * The verification succeeded; there is nothing else a client needs. It knows
 * which channels it has proven because it made those requests and read their
 * answers, which is exactly why this slice publishes no status endpoint.
 * Returning the proof timestamps, or "one channel left", would be that endpoint
 * arriving through a body nobody argued for — and a proof timestamp is a fact
 * about when possession was demonstrated, which no client acts on.
 *
 * EVERY REFUSAL IS ONE `401`
 *
 * `VerifyRegistrationPasscode` answers a bare `false` for all of them — no
 * challenge, expired, superseded, attempts exhausted, wrong code, unbound
 * channel, already proven, registration ended — and a credential that does not
 * resolve is the same `401` before that. The controller could not distinguish
 * them if it wanted to, which is the cheapest guarantee that it never will.
 */
final class VerifyRegistrationPasscodeController
{
    public function __invoke(
        VerifyRegistrationPasscodeRequest $request,
        RegistrationService $registrations,
        VerifyRegistrationPasscode $verify,
    ): Response {
        $registration = $registrations->resolve($request->credential())
            ?? throw new AuthenticationException('The registration credential is not valid.');

        if (! $verify($registration, $request->channel(), $request->passcode())) {
            // Fixed text, containing nothing the caller supplied, and identical
            // for every reason the passcode did not work.
            throw new AuthenticationException('The passcode is not valid.');
        }

        // 204 with a genuinely empty body, as documented.
        return response()->noContent();
    }
}
