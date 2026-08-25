<?php

declare(strict_types=1);

namespace App\Routes;

use RuntimeException;

/**
 * Cancellation was refused because the journey's state does not allow it.
 *
 * One case today: the departure has passed. It is separate from
 * RoutePublicationRefused because the two answer different questions — that one
 * is about an id, this one is about a moment — and folding them together would
 * mean a caller had to ask which kind of refusal it had received.
 */
final class RouteCancellationRefused extends RuntimeException
{
    public static function departureHasPassed(): self
    {
        return new self('The journey has already departed and cannot be cancelled.');
    }
}
