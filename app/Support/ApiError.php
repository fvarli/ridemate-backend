<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single error shape every RideMate API response uses.
 *
 * WHY `code` MATTERS MORE THAN `message`
 *
 * The Flutter client owns its own copy: 210 approved localization keys with
 * Turkish as the source language. If the server sent display text, message
 * ownership would fork across two repositories and two release cadences, and
 * the app would show a language its translators never approved.
 *
 * So `code` is the contract. It is a stable machine string the client maps to
 * one of its own keys, and renaming one is a contract change. `message` is
 * developer-facing English for logs and debugging, and the client must never
 * display it — the OpenAPI description says so explicitly.
 *
 * `details` appears only where it means something, which today is validation.
 * `request_id` always matches the X-Request-Id response header, so a member's
 * screenshot is enough to find the log line.
 */
final class ApiError
{
    public const BAD_REQUEST = 'bad_request';

    public const UNAUTHENTICATED = 'unauthenticated';

    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    public const CONFLICT = 'conflict';

    public const VALIDATION_FAILED = 'validation_failed';

    public const RATE_LIMITED = 'rate_limited';

    public const INTERNAL_ERROR = 'internal_error';

    /**
     * Builds the error response.
     *
     * @param  array<string, list<string>>|null  $details
     */
    public static function response(
        Request $request,
        string $code,
        string $message,
        int $status,
        ?array $details = null,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        $error['request_id'] = RequestId::get($request);

        return new JsonResponse(['error' => $error], $status);
    }
}
