<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Registration\MintedRegistration;
use Illuminate\Http\JsonResponse;

/**
 * The one place a started registration's body is shaped.
 *
 * TWO FIELDS, AND WHAT IS DELIBERATELY ABSENT
 *
 * The credential, and when the registration stops being advanceable. That is
 * everything a client needs to run the rest of the flow: it knows which
 * channels it has proven, because it made those requests itself and read their
 * answers, and it knows how long it has.
 *
 * `registration_id` is absent. It travels inside the credential already — which
 * is what makes resolution a primary-key read — and publishing it separately
 * would hand clients a second name for the registration that looks like an
 * address and is not an authorization. The first client to put it in a path
 * would be building the thing this contract refuses to have.
 *
 * No proof state is here either: no `email_verified_at`, no
 * `phone_verified_at`, no "channels remaining". A registration that has just
 * started has proven nothing, so the fields would be a constant; publishing
 * them would be designing the status endpoint this slice decided not to build.
 *
 * `expires_at` rather than an `expires_in`: this is a fact about a registration
 * resource, not the lifetime of a token, and the conventions put a timestamp on
 * the wire as RFC 3339 UTC. A client showing "you have ten minutes left" can
 * subtract; a client resuming after the process was killed cannot reconstruct a
 * duration it never stored.
 */
final class StartedRegistrationResponse
{
    public static function from(MintedRegistration $minted): JsonResponse
    {
        return new JsonResponse([
            'registration_credential' => $minted->credential,
            // Atom is RFC 3339 with an explicit offset, which is what the
            // contract's date-time format means, and what every other
            // timestamp in this API is rendered with.
            'expires_at' => $minted->expiresAt->toAtomString(),
        ], JsonResponse::HTTP_CREATED);
    }
}
