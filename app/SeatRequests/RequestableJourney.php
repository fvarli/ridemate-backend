<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Route;
use App\Routes\Recurrence;
use Carbon\CarbonImmutable;

/**
 * Which dated journey of a route may be asked about, right now.
 *
 * ONE OWNER, BECAUSE TWO SIDES ASK THE SAME QUESTION
 *
 * `App\SeatRequests\RequestSeat` asks it to decide what a create is for, and
 * discovery asks it to decide which of the caller's existing askings to show.
 * Those must agree: a date discovery still shows is a date the ask path would
 * still accept, and a date it would refuse must not be offered back as though
 * it could be acted on. Written twice they would drift the first time the
 * horizon or the weekday rule moved, and the drift would surface as a card that
 * says one thing and a `422` that says another.
 *
 * So the rule lives here once, in two shapes of the same decision: `resolve`
 * for the writer, which needs to know WHY it is refused, and `admits` for the
 * reader, which only needs to know THAT it is.
 *
 * A ROUTE IS A PLAN; AN ASKING IS FOR ONE OF ITS DAYS
 *
 * A one-off route has a single day, so the caller need not name it and older
 * clients do not — but if they do, it must be that day. A plan has many, so the
 * day is required and is checked against three separate questions, each with
 * its own answer: does the route run then, is it inside the horizon, and has it
 * already left.
 *
 * EVERY DATE RULE IS BORROWED, NONE IS RECOMPUTED
 *
 * The weekday, the route's today, the departure instant and the horizon all
 * come from the classes that own them — `App\Routes\RouteDeparture` and
 * `SeatRequestHorizon`. Nothing here does arithmetic of its own, so there is no
 * second definition of a weekday or of when a dated journey leaves.
 */
final class RequestableJourney
{
    private function __construct() {}

    /**
     * The dated journey this asking is for, or why there is none.
     *
     * The writer's shape. Called under the route lock, against values read
     * inside it.
     *
     * @throws ServiceDateRefused when the named day is not one this route can
     *                            be asked about.
     */
    public static function resolve(
        Route $route,
        ?CarbonImmutable $named,
        ?CarbonImmutable $now = null,
    ): CarbonImmutable {
        $decision = self::decide($route, $named, $now);

        if ($decision instanceof ServiceDateRefused) {
            throw $decision;
        }

        return $decision;
    }

    /**
     * May this route be asked about for that day, as things stand?
     *
     * The reader's shape, and deliberately a boolean: a screen showing the
     * caller their own askings has no use for a reason, and rendering one would
     * be explaining a refusal nobody asked for.
     *
     * IT ALSO CHECKS DEPARTURE FOR A ONE-OFF ROUTE, WHICH `resolve` DOES NOT
     *
     * On the ask path a departed one-off journey is already gone before the
     * date is looked at — `RequestSeat` answers the way an unknown id does,
     * because a journey nothing can see must not be confirmed to exist. That is
     * a disclosure rule about creating, not a statement that the day is still
     * open. A reader asking this question directly gets the honest answer.
     */
    public static function admits(
        Route $route,
        CarbonImmutable $serviceDate,
        ?CarbonImmutable $now = null,
    ): bool {
        if (! self::decide($route, $serviceDate, $now) instanceof CarbonImmutable) {
            return false;
        }

        // A no-op for a recurring route, which `decide` has already answered
        // this for. Written unconditionally so the one-off case cannot be
        // reached by a reader who forgot it.
        return ! $route->departure()->hasDeparted($serviceDate, $now);
    }

    /**
     * The decision itself, as a value rather than as control flow.
     *
     * Returning the refusal instead of throwing it is what lets one body serve
     * both shapes above: a caller that wants the reason gets the object, a
     * caller that wants a yes or no reads the type.
     */
    private static function decide(
        Route $route,
        ?CarbonImmutable $named,
        ?CarbonImmutable $now,
    ): CarbonImmutable|ServiceDateRefused {
        $departure = $route->departure();

        if ($route->recurrence === Recurrence::Once) {
            $only = $route->soleServiceDate();

            if ($named !== null && $named->format('Y-m-d') !== $only->format('Y-m-d')) {
                return ServiceDateRefused::notThisRoutesDay($named);
            }

            return $only;
        }

        if ($named === null) {
            return ServiceDateRefused::missing();
        }

        if (! $departure->runsOn($named)) {
            return ServiceDateRefused::notAServiceDate($named);
        }

        // DEPARTURE BEFORE HORIZON, AND THE ORDER IS LOAD-BEARING. The horizon
        // is bounded at both ends — a day already behind us is as far outside
        // it as a day three weeks ahead — so asked first it would answer "at
        // most fourteen days ahead, and yesterday is further out", which is
        // false about yesterday. The departure question is the specific one for
        // a day that has gone, so it is asked first and the horizon is left
        // saying only what it is for.
        if ($departure->hasDeparted($named, $now)) {
            return ServiceDateRefused::alreadyDeparted($named);
        }

        if (! SeatRequestHorizon::admits($departure, $named, $now)) {
            return ServiceDateRefused::beyondHorizon($named);
        }

        return $named;
    }
}
