<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\LogRequest;
use App\Support\ExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        // Laravel's built-in /up is deliberately not enabled: it renders a
        // view and reports nothing about dependencies. /health and /ready in
        // routes/ops.php are the documented contract.
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/ops.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepended so a correlation id exists before anything can fail.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(LogRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every client of this service speaks JSON. There is no web UI, so a
        // rendered HTML error page would only ever be a leak.
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);

        $exceptions->render(
            static fn (Throwable $e, Request $request) => ExceptionRenderer::render($e, $request),
        );
    })->create();
