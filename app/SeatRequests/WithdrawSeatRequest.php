<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\SeatRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A passenger taking back their own asking.
 *
 * THE ROUTE IS NOT CONSULTED
 *
 * Withdrawing closes a request; it creates no obligation and consumes no
 * capacity, so a journey that was cancelled or has departed does not stop it.
 * The alternative would strand a passenger's own pending request forever
 * because somebody else changed something.
 *
 * ACCEPTED IS AN END
 *
 * A seat the driver agreed to give cannot be handed back here. The driver has
 * planned around it and nothing tells them it went away — releasing it needs a
 * notification that does not exist, so Phase 13 v1 refuses rather than pretends.
 */
final class WithdrawSeatRequest
{
    public function __construct(private readonly AuthorizedSeatRequest $requests) {}

    public function __invoke(
        Account $passenger,
        string $requestId,
        ?CarbonImmutable $now = null,
    ): TransitionedSeatRequest {
        return DB::transaction(function () use ($passenger, $requestId, $now): TransitionedSeatRequest {
            $request = $this->requests->forPassenger($passenger, $requestId);

            return match ($request->status) {
                // Already there. Nothing is written, not even a timestamp: a
                // repeated command is one withdrawal observed twice.
                SeatRequestStatus::Withdrawn => new TransitionedSeatRequest(
                    $request,
                    wasAlreadyInTargetState: true,
                ),
                SeatRequestStatus::Accepted => throw SeatRequestRefused::alreadyAccepted(),
                SeatRequestStatus::Declined => throw SeatRequestRefused::alreadyDecided(),
                SeatRequestStatus::Pending => $this->withdraw($request, $now),
            };
        });
    }

    private function withdraw(
        SeatRequest $request,
        ?CarbonImmutable $now,
    ): TransitionedSeatRequest {
        $request->status = SeatRequestStatus::Withdrawn;
        $request->withdrawn_at = $now ?? CarbonImmutable::now();
        $request->save();

        return new TransitionedSeatRequest($request, wasAlreadyInTargetState: false);
    }
}
