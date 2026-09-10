<?php

declare(strict_types=1);

namespace App\Trips;

/**
 * The four states a caller can be told about, including the one nothing stores.
 *
 * `TripStatus` is what a row can say; this is what a route can say. The
 * difference is `NotStarted`, which exists precisely because no row does.
 */
enum TripState: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Aborted = 'aborted';
}
