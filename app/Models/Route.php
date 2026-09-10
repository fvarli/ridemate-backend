<?php

declare(strict_types=1);

namespace App\Models;

use App\Routes\DepartureState;
use App\Routes\Recurrence;
use App\Routes\RouteDeparture;
use App\Routes\RouteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A journey a driver has published.
 *
 * ON THE ID
 *
 * HasUuids is present for the key type, not to mint one: it only fills a key
 * that is still empty, and publication always assigns the client's id first.
 * That is what makes a retried publication idempotent.
 *
 * NOTHING HERE COSTS ANYTHING
 *
 * There is no cost property, and no method computes one. RideMate shares
 * journey costs and charges nobody, so there is no amount for this class to
 * hold.
 *
 * @property string $id
 * @property string $account_id
 * @property string $origin_place_id
 * @property string $destination_place_id
 * @property Recurrence $recurrence
 * @property ?CarbonImmutable $departure_date
 * @property string $departure_time
 * @property string $timezone
 * @property int $seats_offered
 * @property bool $rule_no_smoking
 * @property bool $rule_music_ok
 * @property bool $rule_no_pets
 * @property bool $rule_quiet
 * @property RouteStatus $status
 * @property CarbonImmutable $published_at
 * @property ?CarbonImmutable $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Account $account
 * @property-read ?Trip $trip
 * @property-read Place $originPlace
 * @property-read Place $destinationPlace
 */
class Route extends Model
{
    use HasUuids;

    /**
     * Empty, like every model here.
     *
     * Ownership in particular must never be mass-assignable: `account_id` comes
     * from the authenticated credential, and a request that could set it would
     * let a member publish a journey in somebody else's name.
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
            'recurrence' => Recurrence::class,
            // A date, not a datetime: it carries no time of day, and casting it
            // to one would attach midnight in some zone and invite comparisons
            // against an instant this column does not represent.
            'departure_date' => 'immutable_date',
            'seats_offered' => 'integer',
            'rule_no_smoking' => 'boolean',
            'rule_music_ok' => 'boolean',
            'rule_no_pets' => 'boolean',
            'rule_quiet' => 'boolean',
            'status' => RouteStatus::class,
            'published_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The making of this journey, if it has been started.
     *
     * Zero or one, enforced by a unique `route_id`. Null is not a gap in the
     * data — it is `not_started`, which nothing stores. See
     * `App\Trips\TripLifecycle`.
     *
     * @return HasOne<Trip, $this>
     */
    public function trip(): HasOne
    {
        return $this->hasOne(Trip::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function originPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'origin_place_id');
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function destinationPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'destination_place_id');
    }

    /**
     * The stored departure, back in the terms the driver chose it in.
     */
    public function departure(): RouteDeparture
    {
        return RouteDeparture::fromInput(
            $this->recurrence,
            $this->departure_date?->format(RouteDeparture::DATE_FORMAT),
            // PostgreSQL hands back `08:00:00`; the domain speaks in `08:00`.
            substr($this->departure_time, 0, 5),
            $this->timezone,
        );
    }

    /**
     * Read from the clock, never from a column.
     */
    public function departureState(?CarbonImmutable $now = null): DepartureState
    {
        return $this->departure()->state($now);
    }

    public function isPublished(): bool
    {
        return $this->status === RouteStatus::Published;
    }

    /**
     * Cancellation is refused once the journey has already happened.
     *
     * Not an authorisation question — the owner is still the owner. It is that
     * cancelling something in the past would rewrite history rather than change
     * a plan.
     */
    public function isCancellable(?CarbonImmutable $now = null): bool
    {
        return $this->departureState($now) === DepartureState::Upcoming;
    }
}
