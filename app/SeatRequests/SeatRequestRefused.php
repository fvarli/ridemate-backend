<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\SeatRequest;
use RuntimeException;

/**
 * The domain refusing to create an asking, and why.
 *
 * Carries a `RefusalReason` rather than an HTTP status: the domain knows what
 * it refused, and the controller knows what that is worth over HTTP. Same split
 * as `App\Routes\RoutePublicationRefused`.
 *
 * `$existing` is the request whose state caused the refusal, attached so the
 * response layer can tell the caller where it now stands without a second
 * query. It is present ONLY where the caller is already entitled to see that
 * state: their own asking (`AlreadyRequested`), or one they are answering on a
 * journey they own (`AlreadyAccepted`, `AlreadyDecided`, `Withdrawn`).
 *
 * It is never attached for `IdAlreadyUsed`, where the row may belong to
 * somebody else and its status is none of this caller's business — that
 * refusal says the id is taken and stops there.
 */
final class SeatRequestRefused extends RuntimeException
{
    private function __construct(
        public readonly RefusalReason $reason,
        string $message,
        public readonly ?SeatRequest $existing = null,
    ) {
        parent::__construct($message);
    }

    public static function profileRequired(): self
    {
        return new self(
            RefusalReason::ProfileRequired,
            'A seat request names its passenger, so the caller needs a profile first.',
        );
    }

    public static function ownRoute(): self
    {
        return new self(
            RefusalReason::OwnRoute,
            'A member cannot request a seat on their own journey.',
        );
    }

    public static function recurringRouteUnsupported(): self
    {
        return new self(
            RefusalReason::RecurringRouteUnsupported,
            'Seat requests are supported for one-off journeys only.',
        );
    }

    /**
     * Says the id is taken. Whose it is, and what it points at, are none of
     * this caller's business — so nothing is attached.
     */
    public static function idAlreadyUsed(): self
    {
        return new self(
            RefusalReason::IdAlreadyUsed,
            'That seat request id is already in use.',
        );
    }

    public static function alreadyAccepted(SeatRequest $existing): self
    {
        return new self(
            RefusalReason::AlreadyAccepted,
            'That seat request has already been accepted.',
            $existing,
        );
    }

    public static function alreadyDecided(SeatRequest $existing): self
    {
        return new self(
            RefusalReason::AlreadyDecided,
            'That seat request has already been decided.',
            $existing,
        );
    }

    public static function withdrawn(SeatRequest $existing): self
    {
        return new self(
            RefusalReason::Withdrawn,
            'That seat request was withdrawn by the passenger.',
            $existing,
        );
    }

    public static function routeUnavailable(): self
    {
        return new self(
            RefusalReason::RouteUnavailable,
            'That journey is no longer running, so no seat can be given on it.',
        );
    }

    public static function routeFull(): self
    {
        return new self(
            RefusalReason::RouteFull,
            'Every offered seat on that journey is already accepted.',
        );
    }

    public static function alreadyRequested(SeatRequest $existing): self
    {
        return new self(
            RefusalReason::AlreadyRequested,
            'This member has already requested a seat on this journey.',
            $existing,
        );
    }
}
