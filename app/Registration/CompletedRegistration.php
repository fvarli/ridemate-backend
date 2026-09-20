<?php

declare(strict_types=1);

namespace App\Registration;

use App\Auth\IssuedTokenPair;
use App\Models\Account;

/**
 * What a registration became: one account, and the one session it opened.
 *
 * WHY THE TOKENS TRAVEL WITH THE ACCOUNT RATHER THAN BEING FETCHED LATER
 *
 * Because they cannot be fetched later. `IssuedTokenPair` is the only moment
 * the plaintext credentials exist server-side, and there is no second chance at
 * them — which is also the reason completion may not be replayed for a fresh
 * one. A caller that missed this object has to sign in normally, exactly as a
 * client that lost the response will have to.
 *
 * STILL NOT A RESPONSE
 *
 * `POST /api/v1/registrations/complete` reads `tokens` off one of these and
 * hands it to `TokenPairResponse`, the same shaper sign-in and refresh use. The
 * `account` is deliberately NOT published: `GET /api/v1/me` is the endpoint that
 * publishes an account, it is authenticated by the token pair this carries, and
 * a second projection of the same row would be a second decision about what an
 * account may say.
 *
 * So this remains the internal result of a domain transaction — what it did,
 * rather than what a caller is told.
 */
final readonly class CompletedRegistration
{
    public function __construct(
        public Account $account,
        public IssuedTokenPair $tokens,
    ) {}
}
