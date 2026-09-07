<?php

declare(strict_types=1);

namespace App\Models;

use App\Profiles\DisplayName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's public identity.
 *
 * Exactly one per account, enforced by a unique constraint rather than by
 * application code — see the migration.
 *
 * `initials` is a method, not a column and not an attribute. It is derived from
 * the display name on every read, so the two cannot drift apart, and the
 * derivation lives in App\Profiles\DisplayName where Phase 12 will reuse it.
 *
 * @property string $id
 * @property string $account_id
 * @property string $display_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Account $account
 */
class Profile extends Model
{
    use HasUuids;

    /**
     * The one field a member supplies.
     *
     * `account_id` is absent deliberately: ownership comes from the
     * authenticated context, never from a payload, and a mass-assignable owner
     * is how one member writes another member's profile.
     *
     * @var list<string>
     */
    protected $fillable = ['display_name'];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Derived on every read, never stored. */
    public function initials(): string
    {
        return DisplayName::fromInput($this->display_name)->initials();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
