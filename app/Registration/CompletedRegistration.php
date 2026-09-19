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
 * NOT A RESPONSE
 *
 * No controller builds one of these and no payload publishes one. What a public
 * completion endpoint returns — whether it publishes the account at all, and in
 * which shape — is the next slice's question. This is the internal result of a
 * domain transaction, and it says what that transaction actually did.
 */
final readonly class CompletedRegistration
{
    public function __construct(
        public Account $account,
        public IssuedTokenPair $tokens,
    ) {}
}
