<?php

declare(strict_types=1);

namespace App\SeatRequests;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The day named on a create is not a day this route can be asked about.
 *
 * WHY THIS IS NOT A REFUSAL REASON
 *
 * A refusal reason describes mutable state that moved: somebody else took the
 * seat, the driver withdrew the journey, the asking already exists. These are
 * none of that. A Saturday is never a weekday, a date three weeks out is never
 * inside a fourteen-day horizon, and the client's own picker knows both before
 * it sends anything — so a value that arrives here is a malformed request, and
 * the honest answer is `422` on the field that carries it.
 *
 * It is also why no new string joins the published vocabulary: a client cannot
 * usefully branch on these, because it should never produce them.
 *
 * Safe to name plainly. A published, upcoming plan is publicly discoverable, so
 * saying which day was wrong discloses nothing that discovery does not.
 *
 * `App\Routes\RefusalReason::InvalidJourney` is the same shape one domain over:
 * the command says what is wrong, and the controller decides it is a `422`.
 */
final class ServiceDateRefused extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self(
            'A recurring journey must be asked about for a specific service date.',
        );
    }

    public static function notAServiceDate(CarbonImmutable $day): self
    {
        return new self(sprintf(
            'This route does not run on %s.',
            $day->format('Y-m-d'),
        ));
    }

    public static function beyondHorizon(CarbonImmutable $day): self
    {
        return new self(sprintf(
            'A seat may be asked for at most %d days ahead, and %s is further out.',
            SeatRequestHorizon::MAX_DAYS_AHEAD,
            $day->format('Y-m-d'),
        ));
    }

    public static function alreadyDeparted(CarbonImmutable $day): self
    {
        return new self(sprintf(
            'The journey on %s has already departed.',
            $day->format('Y-m-d'),
        ));
    }

    /** A one-off journey has one day, and it is not the caller's to choose. */
    public static function notThisRoutesDay(CarbonImmutable $day): self
    {
        return new self(sprintf(
            'This journey runs on a single day, which is not %s.',
            $day->format('Y-m-d'),
        ));
    }
}
