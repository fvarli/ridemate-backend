<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One structured line per request.
 *
 * SENSITIVE DATA POLICY: request and response bodies, headers, query strings
 * and route parameters are NOT logged — not redacted, not truncated, not
 * logged. A redaction list is a denylist, and a denylist is wrong by default
 * the first time a new sensitive field is added and nobody updates it.
 *
 * What is logged is an explicit allowlist of scalars that cannot carry a
 * secret: the method, the matched route pattern rather than the concrete URI
 * (so an id or a token in a path cannot leak), the status, the duration, and
 * the correlation id. LogRequestTest asserts the key set exactly, so widening
 * it is a deliberate act with a failing test in the way.
 */
final class LogRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        Log::info('http_request', [
            'request_id' => RequestId::get($request),
            'method' => $request->getMethod(),
            // The route pattern, never the raw URI: /users/{id} rather than a
            // path that might contain an identifier or a one-time token.
            'route' => $request->route()?->uri() ?? 'unmatched',
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'environment' => app()->environment(),
            'release' => config('app.release'),
        ]);

        return $response;
    }
}
