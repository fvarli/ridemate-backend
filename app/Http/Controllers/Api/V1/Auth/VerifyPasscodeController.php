<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\AuthenticateByPhone;
use App\Http\Requests\VerifyPasscodeRequest;
use App\Http\Responses\TokenPairResponse;
use App\Otp\OtpService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/auth/otp/verify`.
 *
 * Two steps, in this order, and the order is the security property: prove
 * possession of the number first, then decide who that makes you. Nothing about
 * an account is consulted until a passcode has actually been verified, so a
 * caller cannot learn anything by submitting one.
 *
 * Every passcode failure — none outstanding, expired, superseded, attempts
 * exhausted, simply wrong — arrives here as the same `false` and leaves as the
 * same 401. The controller could not distinguish them if it wanted to, which
 * is the cheapest possible guarantee that it never will.
 */
final class VerifyPasscodeController
{
    public function __invoke(
        VerifyPasscodeRequest $request,
        OtpService $otp,
        AuthenticateByPhone $authenticate,
    ): JsonResponse {
        $phone = $request->phoneE164();

        if (! $otp->verify($phone, $request->passcode())) {
            // Fixed text, containing nothing the caller supplied, and identical
            // for every reason the passcode did not work.
            throw new AuthenticationException('The passcode is not valid.');
        }

        // Creates the account if this number has never signed in before, and
        // refuses with 403 if it belongs to a suspended member.
        return TokenPairResponse::from($authenticate($phone, $request->device()));
    }
}
