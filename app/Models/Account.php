<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A credential identity: a verified phone number, and whether it may sign in.
 *
 * ON HasUuids
 *
 * Laravel's HasUuids IS the UUIDv7 trait — its newUniqueId() returns
 * Str::uuid7(). HasVersion4Uuids is the opt-DOWN to random ids. This is worth
 * stating because the name suggests otherwise and the wrong guess costs an
 * index: v4 scatters inserts across the whole B-tree, v7 appends.
 *
 * @property string $id
 * @property string $phone_e164
 * @property CarbonImmutable $phone_verified_at
 * @property AccountStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, AuthSession> $authSessions
 */
class Account extends Model
{
    use HasUuids;

    /**
     * Deliberately narrow.
     *
     * `status` is absent: suspension is an operator act with its own command,
     * not something a request payload may set. `phone_e164` is absent for the
     * same reason in reverse — it is written once, by the passcode flow, from a
     * normalized value, and a mass-assignable phone number is an account
     * takeover waiting for a careless controller.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /** Whether this account may authenticate at all. */
    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'immutable_datetime',
            'status' => AccountStatus::class,
        ];
    }
}
