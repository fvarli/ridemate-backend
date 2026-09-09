<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Profile;
use App\Models\SeatRequest;

/**
 * One asking on a journey the caller published.
 *
 * The route is not carried: the caller addressed it by id and owns it, so
 * repeating it on every row would be noise. The passenger is a `Profile` and
 * only a `Profile` — the same rule discovery applies to drivers, pointed the
 * other way.
 */
final readonly class IncomingSeatRequest
{
    public function __construct(
        public SeatRequest $request,
        public Profile $passenger,
    ) {}
}
