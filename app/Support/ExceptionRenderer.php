<?php

declare(strict_types=1);

namespace App\Support;

use App\Registration\RefusalReason as RegistrationRefusalReason;
use App\Registration\RegistrationAdvanceRefused;
use App\Registration\RegistrationCompletionRefused;
use App\Reviews\ReviewRefused;
use App\SeatRequests\RefusalReason;
use App\SeatRequests\SeatRequestRefused;
use App\Trips\TripRefused;
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
            // `$details` is passed because one family of refusals derives its
            // message from the reason that SURVIVED the mapping rather than
            // from the exception. See self::message().
            self::message($e, $code, $status, $details),
            $status,
            $details,
        );
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, mixed>|null}
     */
    private static function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [ApiError::VALIDATION_FAILED, 422, $e->errors()],
            // Before the generic HttpExceptionInterface arm below, which
            // answers every refusal with `details: null`. A seat request has
            // ten distinct ways to be refused and the client may never display
            // `message`, so without a machine-readable discriminator the app
            // would show one generic sentence for states the server knows
            // exactly.
            $e instanceof SeatRequestRefused => self::refusal($e),
            // Beside it rather than through it. Two of the six trip reasons
            // share a wire string with a seat-request reason and none of the
            // rest do; folding them into one arm would make either domain's
            // next reason an edit to the other's mapping.
            $e instanceof TripRefused => [
                ApiError::CONFLICT,
                409,
                ['reason' => $e->reason->value],
            ],
            // And beside both, for the same reason. All five review reasons
            // are conflicts — none is a malformed request — so unlike a seat
            // request there is no status to branch on, only a reason to carry.
            $e instanceof ReviewRefused => [
                ApiError::CONFLICT,
                409,
                ['reason' => $e->reason->value],
            ],
            // And beside all three, but unlike any of them the reason is
            // TRANSLATED rather than published. See self::registration().
            $e instanceof RegistrationAdvanceRefused,
            $e instanceof RegistrationCompletionRefused => self::registration($e->reason),
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

    /**
     * A seat-request refusal, as a status, a code and a stable reason.
     *
     * `profile_required` is a 422 rather than a 409: the target is a valid
     * journey and the request is well formed — what is missing is the caller's
     * own prerequisite, which is the shape validation already describes.
     * Everything else is the resource not being in the expected state, which
     * is what `conflict` means.
     *
     * `details.reason` is the contract; renaming one is breaking. `message`
     * stays developer-facing English that no client displays.
     *
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    private static function refusal(SeatRequestRefused $e): array
    {
        $details = ['reason' => $e->reason->value];

        // Only where the caller is already entitled to see this row: their own
        // asking, or one on a journey they own. `id_already_used` deliberately
        // carries nothing — the row behind it may be somebody else's.
        if ($e->existing !== null) {
            $details['current_status'] = $e->existing->status->value;
        }

        return $e->reason === RefusalReason::ProfileRequired
            ? [ApiError::VALIDATION_FAILED, 422, $details]
            : [ApiError::CONFLICT, 409, $details];
    }

    /**
     * A registration refusal, as a status, a code and — where one may be given
     * at all — a wire reason that is NOT the internal one.
     *
     * THIS IS THE ONLY ARM THAT TRANSLATES
     *
     * Seat requests, trips and reviews publish `$e->reason->value` verbatim,
     * one case to one string. Registration does not, and the difference is the
     * decision rather than an inconsistency.
     *
     * `EmailAlreadyRegistered` and `PhoneAlreadyRegistered` are one wire
     * string. Each answers "does an account already exist for THIS identifier?"
     * — the question an enumeration attempt asks, and one the sign-in path has
     * never been willing to answer. Kept apart on the wire they would let a
     * caller aim a registration at an address and a number and read back which
     * half was taken; collapsed, the answer is the one thing a client can act
     * on — you already have an account, sign in — and says nothing about which
     * identifier produced it. The domain keeps the distinction because an
     * operator reading a refusal needs it; the caller does not get it.
     *
     * `RegistrationEnded` carries NO reason and is a 401, which is the same
     * answer a malformed, unknown or wrong-secret credential gets from
     * `RegistrationService::resolve()`. Giving it a 409 with a reason of its
     * own would tell the holder of a credential whether the registration behind
     * it expired or was already finished — which is completion history for a
     * registration they have just demonstrated they cannot advance.
     *
     * The two that ARE published are facts about the caller's own registration
     * and about nothing else: it has not proven both channels, or it already
     * names a different destination on the one asked about. Neither names an
     * identifier, and neither is reachable without holding the credential.
     *
     * @return array{0: string, 1: int, 2: array<string, mixed>|null}
     */
    private static function registration(RegistrationRefusalReason $reason): array
    {
        if ($reason === RegistrationRefusalReason::RegistrationEnded) {
            return [ApiError::UNAUTHENTICATED, 401, null];
        }

        return [ApiError::CONFLICT, 409, ['reason' => match ($reason) {
            RegistrationRefusalReason::NotFullyProven => 'not_fully_proven',
            RegistrationRefusalReason::ChannelAlreadyBound => 'channel_already_bound',
            // The collapse. Both internal reasons, one public answer.
            RegistrationRefusalReason::EmailAlreadyRegistered,
            RegistrationRefusalReason::PhoneAlreadyRegistered => 'account_already_exists',
            // `RegistrationEnded` is not an arm here: the guard above returned
            // it, and static analysis knows. The match stays exhaustive over
            // what remains, so a case added to the enum fails here rather than
            // arriving on the wire as whatever the default happened to be.
        }]];
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
     *
     *   A COLLAPSE THE MESSAGE WOULD OTHERWISE UNDO. `RefusalReason` has two
     *   cases for an account that already exists — one for the address, one for
     *   the number — and `self::registration()` publishes ONE `details.reason`
     *   for both. The exception's own message says which, in plain English, so
     *   returning it would hand back through `message` exactly the distinction
     *   `details` was collapsed to hide, and would do it in every environment
     *   because these are not 500s.
     *
     *   So a registration refusal's message is derived from `$details` — the
     *   reason that survived the mapping — rather than from the exception. The
     *   two cannot disagree, because there is only one value left to read by
     *   the time this runs.
     *
     * @param  array<string, mixed>|null  $details
     */
    private static function message(Throwable $e, string $code, int $status, ?array $details = null): string
    {
        if ($status >= 500 && ! config('app.debug')) {
            return 'An unexpected error occurred.';
        }

        if ($e instanceof RegistrationAdvanceRefused || $e instanceof RegistrationCompletionRefused) {
            $reason = $details['reason'] ?? null;

            return match (is_string($reason) ? $reason : null) {
                'not_fully_proven' => 'That registration has not proven both an email address and a phone number.',
                'channel_already_bound' => 'This registration is already bound to a different destination on that channel.',
                'account_already_exists' => 'An account already exists.',
                // No reason at all: the registration has ended, and the answer
                // is word-for-word the one an unresolvable credential gets, so
                // a credential that ended cannot be told from one that never
                // resolved.
                default => 'The registration credential is not valid.',
            };
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
