<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Account;
use App\Models\AuthSession;
use App\Models\AuthToken;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Who is making this request, and on which credential.
 *
 * Carried on the request as an attribute, the same way App\Support\RequestId
 * carries the correlation id. RideMate deliberately does not implement
 * Authenticatable: that contract is shaped around passwords and remember
 * tokens, and an account has neither. Pretending otherwise would add methods
 * that must either lie or throw.
 *
 * The session and token are carried alongside the account because logout needs
 * the session and diagnostics need the generation, and re-deriving either from
 * the credential would mean parsing it twice.
 */
final readonly class AuthContext
{
    public const ATTRIBUTE = 'rm.auth';

    public function __construct(
        public Account $account,
        public AuthSession $session,
        public AuthToken $token,
    ) {}

    public static function tryOf(Request $request): ?self
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        return $context instanceof self ? $context : null;
    }

    /**
     * For routes that are already behind the authentication middleware.
     *
     * The exception is a programming error rather than an authentication
     * failure — it means a controller expecting a member was reached without
     * the middleware that guarantees one, which is a wiring bug and should
     * surface as a 500 rather than a polite 401.
     */
    public static function of(Request $request): self
    {
        return self::tryOf($request)
            ?? throw new RuntimeException('The route is missing the authentication middleware.');
    }
}
