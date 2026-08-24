<?php

declare(strict_types=1);

namespace App\Routes;

use RuntimeException;

/**
 * Publication was refused, and why.
 *
 * Named constructors rather than a message passed in at the call site: each of
 * these is a decision the domain makes, and the reason is part of the decision.
 * The HTTP layer maps `$reason` to a status; nothing else reads the text.
 */
final class RoutePublicationRefused extends RuntimeException
{
    private function __construct(public readonly RefusalReason $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function endpointsAreTheSame(): self
    {
        return new self(
            RefusalReason::InvalidJourney,
            'A journey from a place to itself is not a journey.',
        );
    }

    public static function seatsBelowFloor(): self
    {
        return new self(
            RefusalReason::InvalidJourney,
            'A published route offers at least one seat.',
        );
    }

    public static function departureHasPassed(): self
    {
        return new self(
            RefusalReason::InvalidJourney,
            'The departure has already passed in the route timezone.',
        );
    }

    public static function idBelongsToSomeoneElse(): self
    {
        return new self(RefusalReason::IdAlreadyUsed, 'That route id is already in use.');
    }

    public static function idDescribesADifferentJourney(): self
    {
        return new self(
            RefusalReason::IdAlreadyUsed,
            'That route id already describes a different journey.',
        );
    }
}
