<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Models\Account;
use App\Models\SeatRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A driver saying no.
 *
 * THE ROUTE IS NOT CONSULTED, AND THAT IS A PRODUCT DECISION
 *
 * A pending request may be declined after its journey was cancelled or has
 * departed. Declining creates no obligation and consumes no capacity, and
 * forbidding it would strand pending requests in the driver's list with no way
 * to clear them. The passenger then sees their request `declined` beside a
 * route that is `cancelled` — two independent truths, which is the same shape
 * Phase 13 uses everywhere rather than inventing a state that claims a decision
 * nobody made.
 */
final class DeclineSeatRequest
{
    public function __construct(private readonly AuthorizedSeatRequest $requests) {}

    public function __invoke(
        Account $driver,
        string $requestId,
        ?CarbonImmutable $now = null,
    ): TransitionedSeatRequest {
        return DB::transaction(function () use ($driver, $requestId, $now): TransitionedSeatRequest {
            $request = $this->requests->forDriver($driver, $requestId);

            return match ($request->status) {
                SeatRequestStatus::Declined => new TransitionedSeatRequest(
                    $request,
                    wasAlreadyInTargetState: true,
                ),
                SeatRequestStatus::Accepted => throw SeatRequestRefused::alreadyAccepted($request),
                SeatRequestStatus::Withdrawn => throw SeatRequestRefused::withdrawn($request),
                SeatRequestStatus::Pending => $this->decline($request, $now),
            };
        });
    }

    private function decline(SeatRequest $request, ?CarbonImmutable $now): TransitionedSeatRequest
    {
        $request->status = SeatRequestStatus::Declined;
        $request->decided_at = $now ?? CarbonImmutable::now();
        $request->save();

        return new TransitionedSeatRequest($request, wasAlreadyInTargetState: false);
    }
}
