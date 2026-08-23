<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One issued passcode.
 *
 * The plaintext code is NOT on this model and never will be. It exists in a
 * local variable during issuance, travels to the sender, and is gone.
 *
 * @property string $id
 * @property string $phone_e164
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $invalidated_at
 * @property CarbonImmutable $created_at
 */
class OtpChallenge extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * Neither used nor superseded — the state the partial unique index counts.
     *
     * Says nothing about expiry: an expired row is still unresolved until
     * something invalidates it, which is exactly why issuance invalidates
     * predecessors unconditionally.
     */
    public function isUnresolved(): bool
    {
        return $this->consumed_at === null && $this->invalidated_at === null;
    }

    /** Whether this challenge can still be verified right now. */
    public function isUsable(int $maxAttempts): bool
    {
        return $this->isUnresolved()
            && $this->expires_at->isFuture()
            && $this->attempts < $maxAttempts;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'consumed_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }
}
