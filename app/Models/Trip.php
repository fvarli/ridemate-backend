<?php

declare(strict_types=1);

namespace App\Models;

use App\Trips\TripStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One journey actually being made.
 *
 * Narrow on purpose: the commands that move it between states arrive in B2 and
 * B3, and this row is the thing they will move. `$fillable` is empty like every
 * other model here — mass assignment is how a request body reaches a column
 * nobody meant to expose.
 *
 * WHO IS ABOARD IS NOT A COLUMN
 *
 * There is no participants relation and no snapshot. Accepted seat requests are
 * the single source of participation; a copy taken at start could only ever
 * disagree with it later, and then every reader would have to choose which to
 * believe.
 *
 * @property string $id
 * @property string $route_id
 * @property CarbonImmutable $service_date
 * @property TripStatus $status
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $aborted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Route $route
 */
class Trip extends Model
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
            'status' => TripStatus::class,
            // Which dated journey of the route was made. A date, not a
            // datetime, for the reason `routes.departure_date` is one.
            'service_date' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'aborted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** Whether this trip can still change. */
    public function isInProgress(): bool
    {
        return $this->status === TripStatus::InProgress;
    }
}
