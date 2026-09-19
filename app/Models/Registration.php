<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One registration in progress, and the proofs it has accumulated so far.
 *
 * The plaintext credential is NOT on this model and never will be. It exists
 * in a local variable during minting, travels to the caller, and is gone — only
 * `credential_hash` reaches the database.
 *
 * Nothing here decides anything. Whether a registration may still be advanced
 * is `isAdvanceable()`, and whether it may produce an account is a question no
 * code answers yet: completion does not exist.
 *
 * @property string $id
 * @property string $credential_hash
 * @property string|null $email
 * @property string|null $phone_e164
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $phone_verified_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 */
class Registration extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * Deliberately narrow, as on every model here.
     *
     * `credential_hash` in particular: a mass-assignable one would let a
     * careless controller accept the hash a caller claims and hand them
     * somebody else's registration.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Whether this registration may still be advanced at all.
     *
     * Expiry and completion are two different endings and both are final, so
     * they collapse into one answer here. A caller that could tell them apart
     * would learn whether a credential it does not hold was ever finished.
     */
    public function isAdvanceable(): bool
    {
        return $this->completed_at === null && $this->expires_at->isFuture();
    }

    /**
     * Whether both possessions have been proven for THIS registration.
     *
     * The mature account-creation invariant in one place, so that the slice
     * which eventually creates an account reads it rather than restating it.
     * Nothing calls this yet: completion is not implemented.
     */
    public function isFullyProven(): bool
    {
        return $this->email_verified_at !== null && $this->phone_verified_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
