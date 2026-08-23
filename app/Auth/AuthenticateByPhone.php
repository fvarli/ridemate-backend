<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a number whose possession has just been proven into a session.
 *
 * ONE FLOW, NOT TWO
 *
 * This is where first-time registration and returning sign-in meet, and they
 * are deliberately the same path until the moment an account either exists or
 * does not. RideMate has no separate register endpoint, because having one
 * would mean the client had to know which case applied before asking — and
 * therefore that the server was willing to tell it.
 *
 * An action rather than controller code: it decides whether to create an
 * account, and that is a domain decision with a security consequence, not
 * request handling.
 *
 * WHAT MUST NOT HAPPEN FOR A SUSPENDED ACCOUNT
 *
 * Nothing. No session, no token, and no change to the suspension itself. The
 * whole method runs in one transaction so a refusal cannot leave a half-opened
 * session behind.
 */
final class AuthenticateByPhone
{
    public function __construct(private readonly TokenService $tokens) {}

    /**
     * @throws AuthorizationException when the account exists and is suspended.
     */
    public function __invoke(string $phoneE164, DeviceDescription $device): IssuedTokenPair
    {
        return DB::transaction(function () use ($phoneE164, $device): IssuedTokenPair {
            $account = Account::query()->where('phone_e164', $phoneE164)->first();

            if ($account instanceof Account && ! $account->isActive()) {
                // Rolls back, which is the point: a suspended member must not
                // leave a session row behind as evidence of a sign-in that did
                // not happen.
                throw new AuthorizationException('The account is suspended.');
            }

            $account ??= $this->createAccount($phoneE164);

            return $this->tokens->issue($account, $device);
        });
    }

    /**
     * Only the passcode flow may create an account, and only after a passcode
     * has actually been verified. Nothing else in the application constructs
     * one.
     */
    private function createAccount(string $phoneE164): Account
    {
        $account = new Account;
        $account->phone_e164 = $phoneE164;

        // Set here rather than defaulted in the schema: this timestamp records
        // that possession was proven, and this is the only moment the
        // application knows that to be true.
        $account->phone_verified_at = CarbonImmutable::now();
        $account->save();

        return $account;
    }
}
