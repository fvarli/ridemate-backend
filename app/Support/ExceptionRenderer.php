<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps any throwable onto the one documented error shape.
 *
 * Lives in a class rather than inline in bootstrap/app.php so it can be tested
 * directly and so the mapping table is somewhere a reader can find it.
 */
final class ExceptionRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$code, $status, $details] = self::classify($e);

        return ApiError::response(
            $request,
            $code,
            self::message($e, $status),
            $status,
            $details,
        );
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, list<string>>|null}
     */
    private static function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [ApiError::VALIDATION_FAILED, 422, $e->errors()],
            $e instanceof AuthenticationException => [ApiError::UNAUTHENTICATED, 401, null],
            $e instanceof AuthorizationException => [ApiError::FORBIDDEN, 403, null],
            $e instanceof ModelNotFoundException => [ApiError::NOT_FOUND, 404, null],
            $e instanceof NotFoundHttpException => [ApiError::NOT_FOUND, 404, null],
            $e instanceof MethodNotAllowedHttpException => [ApiError::METHOD_NOT_ALLOWED, 405, null],
            $e instanceof TooManyRequestsHttpException => [ApiError::RATE_LIMITED, 429, null],
            $e instanceof HttpExceptionInterface => [
                self::codeForStatus($e->getStatusCode()),
                $e->getStatusCode(),
                null,
            ],
            default => [ApiError::INTERNAL_ERROR, 500, null],
        };
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => ApiError::BAD_REQUEST,
            401 => ApiError::UNAUTHENTICATED,
            403 => ApiError::FORBIDDEN,
            404 => ApiError::NOT_FOUND,
            405 => ApiError::METHOD_NOT_ALLOWED,
            409 => ApiError::CONFLICT,
            422 => ApiError::VALIDATION_FAILED,
            429 => ApiError::RATE_LIMITED,
            default => ApiError::INTERNAL_ERROR,
        };
    }

    /**
     * Developer-facing English, and never the raw message of an unexpected
     * failure outside a debug build.
     *
     * An exception message can carry a file path, a SQL fragment, a hostname
     * or a credential, and a client could not act on any of it. The log line
     * for this request keeps the detail; the response keeps the code.
     */
    private static function message(Throwable $e, int $status): string
    {
        if ($status >= 500 && ! config('app.debug')) {
            return 'An unexpected error occurred.';
        }

        $message = $e->getMessage();

        return $message !== '' ? $message : 'Request failed.';
    }
}
