<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One logical authenticated device — and the only revocation boundary.
 *
 * Access tokens and refresh generations are both checked against this row, so
 * revoking it invalidates every credential in the family at once without
 * touching auth_tokens at all.
 *
 * @property string $id
 * @property string $account_id
 * @property string|null $device_name
 * @property string|null $platform
 * @property string|null $app_version
 * @property CarbonImmutable $absolute_expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property SessionRevocationReason|null $revoked_reason
 * @property CarbonImmutable $created_at
 * @property-read Account $account
 * @property-read Collection<int, AuthToken> $tokens
 */
class AuthSession extends Model
{
    use HasUuids;

    /**
     * Sessions are created and revoked, never edited, so there is nothing for
     * an `updated_at` to describe that `revoked_at` does not already say.
     */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<AuthToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(AuthToken::class, 'session_id');
    }

    /**
     * Whether this session may still authorise anything.
     *
     * Both halves matter. Revocation is the deliberate end; the absolute
     * expiry is the end that arrives whether anyone acts or not, which is what
     * stops an endlessly-refreshed session from living forever.
     */
    public function isLive(): bool
    {
        return $this->revoked_at === null
            && $this->absolute_expires_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'absolute_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'revoked_reason' => SessionRevocationReason::class,
        ];
    }
}
