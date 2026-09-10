<?php

declare(strict_types=1);

namespace App\Trips;

use RuntimeException;

/**
 * The trip domain refusing a lifecycle command, and why.
 *
 * Carries a `RefusalReason` rather than an HTTP status: the domain knows what
 * it refused, and the controller knows what that is worth over HTTP. Same split
 * as `App\Routes\RoutePublicationRefused` and `App\SeatRequests\SeatRequestRefused`.
 */
final class TripRefused extends RuntimeException
{
    private function __construct(
        public readonly RefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function recurringRouteUnsupported(): self
    {
        return new self(
            RefusalReason::RecurringRouteUnsupported,
            'Trips are supported for one-off journeys only.',
        );
    }

    public static function departureNotReached(): self
    {
        return new self(
            RefusalReason::DepartureNotReached,
            'The scheduled departure has not been reached in the route timezone.',
        );
    }

    public static function routeUnavailable(): self
    {
        return new self(
            RefusalReason::RouteUnavailable,
            'That journey is no longer running, so it cannot be made.',
        );
    }

    public static function tripNotStarted(): self
    {
        return new self(
            RefusalReason::TripNotStarted,
            'That journey has not been started.',
        );
    }

    public static function alreadyCompleted(): self
    {
        return new self(
            RefusalReason::AlreadyCompleted,
            'That journey was already reported as made.',
        );
    }

    public static function alreadyAborted(): self
    {
        return new self(
            RefusalReason::AlreadyAborted,
            'That journey was already abandoned.',
        );
    }
}
