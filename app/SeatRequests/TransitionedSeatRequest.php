<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\SeatRequest;

/**
 * The outcome of a transition, with the one distinction HTTP needs.
 *
 * `wasAlreadyInTargetState` separates a transition that happened from one that
 * had already happened. Both are successes and both answer `200`; only the
 * second wrote nothing.
 */
final readonly class TransitionedSeatRequest
{
    public function __construct(
        public SeatRequest $request,
        public bool $wasAlreadyInTargetState,
    ) {}
}
