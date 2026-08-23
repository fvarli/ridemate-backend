<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AuthContext;
use App\Auth\TokenService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a bearer credential into an AuthContext, or refuses the request.
 *
 * The header is read here and the credential is never touched again: it is not
 * copied onto the request, not stored on the context, and not included in any
 * exception. Phase 8's logging middleware records an allowlist of scalars and
 * never headers, which is what keeps a credential out of the log — but only as
 * long as nothing downstream moves it somewhere that IS logged.
 */
final class AuthenticateToken
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization');

        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            // Same refusal as a wrong credential. Whether the header was
            // missing, misspelled or simply wrong is not the caller's business.
            throw new AuthenticationException('The credential is not valid.');
        }

        $request->attributes->set(
            AuthContext::ATTRIBUTE,
            $this->tokens->authenticate(substr($header, 7)),
        );

        return $next($request);
    }
}
