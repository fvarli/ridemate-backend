<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\SeatRequest;

/**
 * The outcome of asking, with the one distinction the controller needs.
 *
 * `wasAlreadyRequested` separates a create from a retry, which is the whole
 * difference between `201` and `200`. Nothing else about HTTP lives here.
 */
final readonly class SeatRequested
{
    public function __construct(
        public SeatRequest $request,
        public bool $wasAlreadyRequested,
    ) {}
}
