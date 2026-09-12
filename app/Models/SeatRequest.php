<?php

declare(strict_types=1);

namespace App\Models;

use App\SeatRequests\SeatRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One passenger's asking for a seat on one route.
 *
 * Narrow on purpose. The commands that move it between states arrive in the
 * next commit; this row is the thing they will move, and it is written by
 * nothing else.
 *
 * `$fillable` is empty, like every other model here: mass assignment is how a
 * request body reaches a column nobody meant to expose, and every write in this
 * codebase names its attributes.
 *
 * THE DRIVER IS NOT A COLUMN
 *
 * `passenger()` exists; there is deliberately no `driver()`. The driver is the
 * route's owner, reachable as `$request->route->account`, and storing a second
 * copy would create two answers to "whose journey is this" that a later change
 * could put out of step. Authorization for the driver-side commands is derived
 * through the route for the same reason.
 *
 * @property string $id
 * @property string $route_id
 * @property string $account_id
 * @property CarbonImmutable $service_date
 * @property SeatRequestStatus $status
 * @property CarbonImmutable $requested_at
 * @property ?CarbonImmutable $decided_at
 * @property ?CarbonImmutable $withdrawn_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Route $route
 * @property-read Account $passenger
 */
class SeatRequest extends Model
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
            'status' => SeatRequestStatus::class,
            // The dated journey this asking is for. A date, not a datetime,
            // for the reason `routes.departure_date` is one: it carries no
            // time of day, and casting it to an instant would attach midnight
            // in some zone and invite comparisons it does not represent.
            'service_date' => 'immutable_date',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * The member who asked.
     *
     * Named for the role rather than the column: `account()` would be accurate
     * and say nothing, and a reader of the driver-side code needs to see at a
     * glance which of the two members this is.
     *
     * @return BelongsTo<Account, $this>
     */
    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** Whether this asking can still change. */
    public function isPending(): bool
    {
        return $this->status === SeatRequestStatus::Pending;
    }

    /**
     * Whether the driver agreed to give this seat.
     *
     * Terminal: an accepted request cannot be withdrawn or declined, which is
     * what lets a reader act on it without holding a lock.
     */
    public function isAccepted(): bool
    {
        return $this->status === SeatRequestStatus::Accepted;
    }
}
