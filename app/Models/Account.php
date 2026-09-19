<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A credential identity: a verified phone number, and whether it may sign in.
 *
 * ON THE EMAIL COLUMNS, WHICH NOTHING WRITES
 *
 * `email` and `email_verified_at` are capability rather than behaviour. A
 * mature registration will eventually prove both an address and a number and
 * produce an account carrying each; these are where that address will land. No
 * code puts one there today, nothing resolves an account by address, and both
 * are NULL on every existing row — which is the truth about those members
 * rather than a gap in them. They travel together in both directions, enforced
 * by a database CHECK: an account never holds an address it has not proven.
 * "Bound but not yet verified" is a real state, and it belongs to
 * `registrations`, not here.
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
 * @property string|null $email
 * @property CarbonImmutable|null $email_verified_at
 * @property AccountStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, AuthSession> $authSessions
 * @property-read Profile|null $profile
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
     * takeover waiting for a careless controller. `email` and
     * `email_verified_at` are absent for that reason squared: a fillable
     * verification timestamp is a way to claim an address without proving it.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /**
     * This account's public identity, if it has one yet.
     *
     * hasOne rather than hasMany because `profiles.account_id` is unique — the
     * relation states what the database already enforces. Nullable on purpose:
     * an account exists from the moment a phone number is verified, and a
     * profile only once the member has chosen a name. "Authenticated but not
     * yet named" is a real state, and the client routes on it.
     *
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
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
            'email_verified_at' => 'immutable_datetime',
            'status' => AccountStatus::class,
        ];
    }
}
