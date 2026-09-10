<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Trips\TripLifecycle;

/**
 * Whether a journey was made, as its own member sees it.
 *
 * One place formats this, and both surfaces that publish it — the owner's My
 * Routes and the passenger's own seat requests — share it, so the two cannot
 * describe the same lifecycle differently.
 *
 * NO `id`
 *
 * A trip is addressed through its route: there is exactly one, the endpoints
 * are route-scoped, and nothing a client does needs to name it. Publishing an
 * identifier nobody uses is a field somebody eventually depends on.
 *
 * NO POLICY FIELDS
 *
 * No `can_start`, `can_complete`, `can_abort`, `can_review` or `is_active`.
 * This says what has happened; whether a control should be offered is a
 * decision the client makes from that truth plus what the server answers when
 * it tries. Encoding the decision here would fork it across two repositories,
 * and `can_review` in particular would be Phase 15 arriving early by
 * implication.
 *
 * Timestamps are the stored ones, formatted and not derived. `Z` rather than an
 * offset, per the Timestamps row in `docs/api-conventions.md`.
 */
final class TripPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(TripLifecycle $lifecycle): array
    {
        return [
            // `not_started` included: a route nobody has begun says so rather
            // than omitting the object, so a client never has to read absence.
            'state' => $lifecycle->state->value,
            'started_at' => $lifecycle->startedAt?->toIso8601ZuluString(),
            'completed_at' => $lifecycle->completedAt?->toIso8601ZuluString(),
            'aborted_at' => $lifecycle->abortedAt?->toIso8601ZuluString(),
        ];
    }

    /**
     * The whole body of a lifecycle command's response.
     *
     * Nothing else accompanies it. The commands are route-scoped and the caller
     * already holds the route, so returning the journey alongside would be a
     * second copy of something they have — and a second place it could disagree.
     *
     * @return array{trip: array<string, mixed>}
     */
    public static function envelope(TripLifecycle $lifecycle): array
    {
        return ['trip' => self::from($lifecycle)];
    }
}
