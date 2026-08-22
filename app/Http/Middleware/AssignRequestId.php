<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id, on the response and in the logs.
 *
 * Prepended to the global stack so the id exists before anything can fail —
 * an error during middleware is exactly the one worth correlating.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = RequestId::resolve($request);

        // Every log line for the rest of this request carries the id, including
        // ones written by code that knows nothing about this middleware.
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $id);

        return $response;
    }
}
