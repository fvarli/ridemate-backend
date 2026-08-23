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
            self::message($e, $code, $status),
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
     * Developer-facing English, and never anything the caller supplied.
     *
     * Framework messages are useful in a stack trace and wrong in a response
     * body. Two kinds of leak matter here:
     *
     *   REFLECTED INPUT. Laravel answers a 404 with "The route api/v1/x could
     *   not be found." and a 405 with the attempted route plus the list of
     *   verbs it does support. Both echo the request back and the second maps
     *   the surface for whoever is probing it.
     *
     *   INTERNAL DETAIL. ModelNotFoundException also maps to 404, and its
     *   message reads "No query results for model [App\Models\Route] 1234" —
     *   an internal class name and a record id. No model exists yet, so it
     *   cannot fire today; the first one lands in Phase 9, and this branch
     *   means it never can.
     *
     * These are fixed in every environment rather than only in production.
     * A contract that changes shape when APP_DEBUG flips is a contract that
     * gets tested in one shape and shipped in another.
     *
     * The 500 branch keeps its existing behaviour, because a developer running
     * with debug on genuinely needs the real exception and no client is
     * reading it.
     */
    private static function message(Throwable $e, string $code, int $status): string
    {
        if ($status >= 500 && ! config('app.debug')) {
            return 'An unexpected error occurred.';
        }

        return match ($code) {
            ApiError::NOT_FOUND => 'The requested resource was not found.',
            ApiError::METHOD_NOT_ALLOWED => 'The request method is not supported for this resource.',
            // Laravel's own text here is already generic ("The given data was
            // invalid.") and the field-level detail lives in `details`.
            default => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
        };
    }
}
