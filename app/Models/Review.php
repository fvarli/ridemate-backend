<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\ReviewerRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's self-declared rating about one completed relationship.
 *
 * Narrow on purpose: the command that writes it arrives in B2 and the surfaces
 * that publish it in B3. `$fillable` is empty like every other model here —
 * mass assignment is how a request body reaches a column nobody meant to
 * expose, and this row has a client-supplied id, which makes that worse.
 *
 * IMMUTABLE ONCE WRITTEN
 *
 * Phase 15 has no edit, no delete and no re-submission, so `updated_at` never
 * moves after the insert. That is asserted rather than merely intended —
 * without moderation, reporting or a dispute path, a review a member could
 * quietly rewrite is a worse artefact than one they cannot.
 *
 * WHO WROTE IT IS NOT A COLUMN
 *
 * There is no `reviewer_account_id` and no `reviewee_account_id`. Both are
 * derived from the seat request by `App\Reviews\ReviewParticipants`, which is
 * what makes an impossible pairing unrepresentable rather than merely
 * validated. A relation to either account here would be a second path to the
 * same fact and a second thing to keep in agreement.
 *
 * @property string $id
 * @property string $seat_request_id
 * @property ReviewerRole $reviewer_role
 * @property int $rating
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read SeatRequest $seatRequest
 */
class Review extends Model
{
    use HasUuids;

    /**
     * Nothing is mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewer_role' => ReviewerRole::class,
            'rating' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The relationship this review is about.
     *
     * The only relation this model has, and the only one it needs: the two
     * accounts, the route and the trip are all reachable through it, each by
     * exactly one path.
     *
     * @return BelongsTo<SeatRequest, $this>
     */
    public function seatRequest(): BelongsTo
    {
        return $this->belongsTo(SeatRequest::class);
    }

    /** When the member submitted it. A review is never edited, so this is it. */
    public function submittedAt(): CarbonImmutable
    {
        return $this->created_at;
    }
}
