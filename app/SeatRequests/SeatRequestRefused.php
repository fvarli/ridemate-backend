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
 * `$existing` is the caller's own request when one is what caused the refusal.
 * It is attached so the later response layer can tell them where their asking
 * now stands without a second query — and it is attached ONLY for
 * `AlreadyRequested`, which by construction is the caller's own row. It is
 * never attached for `IdAlreadyUsed`, where the row may belong to somebody
 * else and its status is none of this caller's business.
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

    public static function alreadyAccepted(): self
    {
        return new self(
            RefusalReason::AlreadyAccepted,
            'That seat request has already been accepted.',
        );
    }

    public static function alreadyDecided(): self
    {
        return new self(
            RefusalReason::AlreadyDecided,
            'That seat request has already been decided.',
        );
    }

    public static function withdrawn(): self
    {
        return new self(
            RefusalReason::Withdrawn,
            'That seat request was withdrawn by the passenger.',
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
