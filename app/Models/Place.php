<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the pilot's meeting points.
 *
 * Reference data. The application reads it and never writes it: the seed is the
 * only thing that inserts, and nothing updates or deletes. See the migration
 * for why immutability is what makes a route's reference to a place safe.
 *
 * `point` is deliberately absent from this class. It is a generated column the
 * database maintains, no Phase 10 code reads it, and giving it a property here
 * would invite an assignment that PostgreSQL would then reject at write time.
 *
 * @property string $id
 * @property string $slug
 * @property string $label
 * @property string $latitude
 * @property string $longitude
 * @property CarbonImmutable $created_at
 */
class Place extends Model
{
    use HasUuids;

    /**
     * A place is never mass-assigned.
     *
     * The seed sets its properties explicitly, one row at a time, from a
     * catalogue held in code. There is no request payload anywhere that
     * creates or edits one, so there is nothing for this list to permit.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Reference rows are written once and never touched again, so there is no
     * `updated_at` to maintain and no honest value one could hold.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // latitude and longitude stay strings on purpose. They arrive from
        // PostgreSQL's `numeric` as exact decimal text, and casting them to
        // float would trade that for a representation that cannot hold
        // 29.0204425 exactly — on a value whose whole point is precision.
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
